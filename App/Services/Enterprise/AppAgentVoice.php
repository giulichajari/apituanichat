<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use PDO;
use RuntimeException;
final class AppAgentVoice {
 public function __construct(private Core $core, private ?\Closure $transport=null){}
 private function bridge(string $path,array $body):array {
  if($this->transport)return ($this->transport)($path,$body);
  $config=json_decode(file_get_contents('/etc/tuanichat-voz-natural/bridge.json'),true,8,JSON_THROW_ON_ERROR);
  $h=curl_init('http://127.0.0.1:9094/'.$path);$output='';
  curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>35,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$config['secret'],'Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($body,JSON_THROW_ON_ERROR),CURLOPT_WRITEFUNCTION=>static function($h,$chunk)use(&$output){if(strlen($output)+strlen($chunk)>2000000)return 0;$output.=$chunk;return strlen($chunk);}]);
  try{curl_exec($h);if(curl_errno($h)||(int)curl_getinfo($h,CURLINFO_HTTP_CODE)!==200)throw new RuntimeException('La voz natural no está activada, está ocupada o agotó su reserva de prueba.',409);return json_decode($output,true,32,JSON_THROW_ON_ERROR);}finally{curl_close($h);}
 }
 private function allowed(array $c,int $actor,bool $active):void {
  if((int)$c['recipient_id']!==$actor)throw new RuntimeException('Solo el destinatario puede abrir el audio.',403);
  if($active){
   if($c['status']!=='active'||((int)$c['expires_at']!==0&&(int)$c['expires_at']<=time()))throw new RuntimeException('La conversación está cerrada.',409);
   if($this->core->authorize((int)$c['tenant_id'],(int)$c['owner_id'])!=='owner')throw new RuntimeException('Propietario sin autorización.',403);
   $owner=$this->core->query('SELECT owner_id FROM ai_agent_preview_config WHERE tenant_id=?',[$c['tenant_id']])->fetchColumn();
   if((int)$owner!==(int)$c['owner_id'])throw new RuntimeException('Piloto no habilitado.',403);
  }
 }
 public function handle(string $action,int $actor,string $call,string $request,string $sdp=''):array {
  if(!preg_match('/^[a-f0-9]{32}$/D',$call)||!preg_match('/^[a-f0-9]{32}$/D',$request))throw new \InvalidArgumentException('Identificador inválido.');
  if(!in_array($action,['start','poll','stop','ready'],true))throw new \InvalidArgumentException('Acción inválida.');
  $c=$this->core->query('SELECT * FROM ai_app_calls WHERE id=?',[$call])->fetch(PDO::FETCH_ASSOC);
  if(!$c)throw new RuntimeException('Conversación no disponible.',404);
  $this->allowed($c,$actor,false);
  $body=['requestId'=>$request,'call'=>$call,'actor'=>$actor,'tenant'=>(int)$c['tenant_id']];
  try{$this->allowed($c,$actor,$action!=='stop');}catch(\Throwable $e){try{$this->bridge('stop',$body);}catch(\Throwable $ignored){}throw $e;}
  if($action==='start'){
   if((int)$c['tenant_id']!==2||$actor!==51)throw new RuntimeException('Voz natural disponible solo para el destinatario del piloto.',403);
   if(strlen($sdp)>60000||!str_starts_with($sdp,'v=0'))throw new \InvalidArgumentException('Audio inválido.');
   $a=$this->core->query('SELECT name,module,instructions FROM ai_agents WHERE id=? AND tenant_id=?',[$c['agent_id'],$c['tenant_id']])->fetch(PDO::FETCH_ASSOC);
   if(!$a)throw new RuntimeException('Agente no disponible.',409);
   $fields=(new CompanyProfile($this->core))->read((int)$c['tenant_id'],(int)$c['owner_id'])['fields'];
   $history=$this->core->query('SELECT role,text,interrupted FROM ai_app_voice_events WHERE call_id=? ORDER BY created_at DESC,id DESC LIMIT 12',[$call])->fetchAll(PDO::FETCH_ASSOC);
   foreach($history as &$item)$item['text']=mb_substr($item['text'],0,350);unset($item);
   $body['sdp']=$sdp;$body['context']=Core::json(['agente'=>$a['name'],'funcion'=>$a['module'],'objetivo'=>$c['objective'],'datos_empresa'=>$fields,'historial_reciente'=>array_reverse($history),'nota'=>'El historial es parcial. No inventes detalles omitidos. No hay herramientas para realizar acuerdos ni acciones.'],14000);
  }
  $result=$this->bridge($action,$body);
  if($action==='start'){
   $latest=$this->core->query('SELECT * FROM ai_app_calls WHERE id=?',[$call])->fetch(PDO::FETCH_ASSOC);
   try{$this->allowed($latest,$actor,true);}catch(\Throwable $e){$this->bridge('stop',$body);throw $e;}
  }
  // Only events obtained from the private bridge, never browser-supplied transcripts.
  foreach(($result['events']??[]) as $event){
   if(!preg_match('/^[a-f0-9]{64}$/D',(string)($event['id']??''))||!in_array($event['role']??null,['user','assistant'],true)||!is_string($event['text']??null))continue;
   $this->core->transaction(function()use($call,$c,$event){
    $this->core->lock((int)$c['tenant_id']);
    $exists=$this->core->query('SELECT id FROM ai_app_voice_events WHERE id=? AND call_id=?',[$event['id'],$call])->fetchColumn();
    if(!$exists)$this->core->query('INSERT INTO ai_app_voice_events(id,call_id,role,text,interrupted,created_at) VALUES(?,?,?,?,?,?)',[$event['id'],$call,$event['role'],mb_substr($event['text'],0,4000),empty($event['interrupted'])?0:1,(int)$event['at']]);
    elseif(!empty($event['interrupted']))$this->core->query('UPDATE ai_app_voice_events SET interrupted=1 WHERE id=? AND call_id=?',[$event['id'],$call]);
   });
  }
  return $result;
 }
}
