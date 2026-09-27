<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use PDO;
use RuntimeException;
final class SquareInbox {
 public const URL='https://tuanichat.com/apituanichat/enterprise/square/sandbox/webhook';
 public const CONFIG='/etc/tuanichat/square-sandbox-webhook.json';
 public const TYPES=['subscription.created','subscription.updated','invoice.payment_made','invoice.scheduled_charge_failed','invoice.refunded','invoice.updated'];
 public function __construct(private PDO $db){}
 public static function config():array{
  if(!is_readable(self::CONFIG))throw new RuntimeException('not_configured',503);
  $c=json_decode((string)file_get_contents(self::CONFIG),true);
  if(!is_array($c)||($c['url']??'')!==self::URL||($c['environment']??'')!=='sandbox'||empty($c['signature_key'])||empty($c['merchant_id']))throw new RuntimeException('not_configured',503);
  return $c;
 }
 public static function verify(string $raw,string $signature,array $config):bool{
  return strlen($raw)<=1048576&&strlen($signature)===44&&($config['url']??'')===self::URL&&!empty($config['signature_key'])
   &&hash_equals(base64_encode(hash_hmac('sha256',self::URL.$raw,$config['signature_key'],true)),$signature);
 }
 public function receive(string $raw,string $signature,array $config,int $now):array{
  if(!self::verify($raw,$signature,$config))throw new RuntimeException('invalid_signature',403);
  $p=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
  if(!is_array($p)||!is_string($p['event_id']??null)||!preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$p['event_id']))throw new RuntimeException('invalid_event',400);
  if(($p['merchant_id']??'')!==$config['merchant_id'])return ['accepted'=>true,'ignored'=>true];
  $type=$p['type']??'';if(!in_array($type,self::TYPES,true))return ['accepted'=>true,'ignored'=>true];
  $hash=hash('sha256',$raw);
  try{
   $q=$this->db->prepare('INSERT INTO ai_square_sandbox_events(event_id,event_type,body_hash,received_at) VALUES(?,?,?,?)');$q->execute([$p['event_id'],$type,$hash,$now]);
  }catch(\PDOException $e){
   if(!in_array((string)$e->getCode(),['23000','23505'],true))throw $e;
   $q=$this->db->prepare('SELECT body_hash FROM ai_square_sandbox_events WHERE event_id=?');$q->execute([$p['event_id']]);
   if(!hash_equals((string)$q->fetchColumn(),$hash))throw new RuntimeException('event_conflict',409);
   return ['accepted'=>true,'repeat'=>true];
  }
  return ['accepted'=>true,'repeat'=>false];
 }
 public function status(int $actor):array{
  $q=$this->db->prepare('SELECT rol FROM users WHERE id=?');$q->execute([$actor]);
  if(strtoupper((string)$q->fetchColumn())!=='ADMIN')throw new RuntimeException('forbidden',403);
  $configured=false;try{self::config();$configured=true;}catch(RuntimeException){}
  $events=$this->db->query("SELECT event_type,received_at,CASE WHEN event_id LIKE 'tuani-http-%' THEN 'local_test' ELSE 'square' END AS source FROM ai_square_sandbox_events ORDER BY received_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
  return ['environment'=>'sandbox','configured'=>$configured,'events'=>$events,'activates_companies'=>false];
 }
}
