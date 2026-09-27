<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use PDO;
final class SupportRuntime {
 public static function delivery(PDO $db):SupportDelivery {
  return new SupportDelivery($db,(int)($_ENV['BOT_USER_ID']??0),\Closure::fromCallable([self::class,'queuePush']));
 }
 public static function tickets(PDO $db):CompanySupportTickets {
  return new CompanySupportTickets($db,(int)($_ENV['BOT_USER_ID']??0),\Closure::fromCallable([self::class,'queuePush']));
 }
 public static function queuePush(PDO $db,int $chat,int $bot,int $id,string $text,int $now):void{
   $q=$db->prepare('SELECT d.user_id,d.fcm_token FROM device_tokens d JOIN chat_usuarios c ON c.user_id=d.user_id WHERE c.chat_id=? AND d.user_id<>? AND d.is_active=1');$q->execute([$chat,$bot]);
   foreach($q->fetchAll(PDO::FETCH_ASSOC) as $device)(new \App\Services\PushOutbox($db))->enqueue((int)$device['user_id'],$device['fcm_token'],['type'=>'new_message','message_id'=>(string)$id,'chat_id'=>(string)$chat,'sender_name'=>'TuaniBot','body'=>mb_substr($text,0,160)],$now);
 }
 public static function broadcast(array $envelope):void {
  $redis=new \Redis();
  try{if(!$redis->connect('127.0.0.1',6379,1.0))throw new \RuntimeException('redis_unavailable');$redis->publish('tuani:ws:broadcast',json_encode($envelope,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));}
  finally{try{$redis->close();}catch(\Throwable $e){}}
 }
}
