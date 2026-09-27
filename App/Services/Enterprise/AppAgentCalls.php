<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use PDO;
use RuntimeException;
use InvalidArgumentException;

/** Opt-in, authenticated browser conversations. No PSTN calls or contract execution. */
final class AppAgentCalls {
 public const PURPOSES=['support'=>'Soporte','hr'=>'Recursos humanos','advertising'=>'Publicidad','negotiation'=>'Negociar una propuesta','onboarding'=>'Recorrido de TuaniChat'];
 public function __construct(private Core $core,private \Closure $generate){}
 private function owner(int $tenant,int $actor):array {
  if($this->core->authorize($tenant,$actor)!=='owner')throw new RuntimeException('Solo el propietario puede preparar llamadas.',403);
  $c=$this->core->query('SELECT owner_id,daily_limit FROM ai_agent_preview_config WHERE tenant_id=?',[$tenant])->fetch(PDO::FETCH_ASSOC);
  if(!$c||(int)$c['owner_id']!==$actor)throw new RuntimeException('Piloto no habilitado.',403);return $c;
 }
 private function id(string $id):void {if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException('Identificador inválido.');}
 private function text(string $value,int $max):string {
  $value=trim($value);if($value===''||strlen($value)>$max||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$value))throw new InvalidArgumentException('Texto vacío o demasiado largo.');return $value;
 }
 private function row(string $id):array {
  $this->id($id);$c=$this->core->query('SELECT * FROM ai_app_calls WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
  if(!$c)throw new RuntimeException('Llamada no disponible.',404);return $c;
 }
 private function participant(array $c,int $actor):void {
  if((int)$c['recipient_id']===$actor)return;
  if((int)$c['owner_id']===$actor){$this->owner((int)$c['tenant_id'],$actor);return;}
  throw new RuntimeException('Llamada no disponible.',403);
 }
 private function visible(array $c,int $now):array {
  if(in_array($c['status'],['invited','active'],true)&&(int)$c['expires_at']!==0&&(int)$c['expires_at']<=$now)$c['status']='expired';
  unset($c['request_hash']);return $c;
 }
 private function used(int $tenant,int $now):int {
  $start=(int)(floor($now/86400)*86400);
  return (int)$this->core->query('SELECT COUNT(*) FROM ai_agent_previews WHERE tenant_id=? AND created_at>=?',[$tenant,$start])->fetchColumn()
   +(int)$this->core->query('SELECT COUNT(*) FROM ai_app_call_turns WHERE tenant_id=? AND created_at>=?',[$tenant,$start])->fetchColumn();
 }
 public function contacts(int $tenant,int $actor):array {
  $this->owner($tenant,$actor);
  // Only people with an existing one-to-one conversation with the owner. No global directory export.
  return $this->core->query('SELECT DISTINCT u.id,u.name FROM users u JOIN chat_usuarios other ON other.user_id=u.id JOIN chat_usuarios mine ON mine.chat_id=other.chat_id WHERE mine.user_id=? AND u.id<>? AND (SELECT COUNT(*) FROM chat_usuarios members WHERE members.chat_id=mine.chat_id)=2 ORDER BY u.name,u.id LIMIT 200',[$actor,$actor])->fetchAll(PDO::FETCH_ASSOC);
 }
 public function listing(int $tenant,int $actor,int $now):array {
  $config=$this->owner($tenant,$actor);
  $rows=$this->core->query('SELECT c.*,u.name AS recipient_name FROM ai_app_calls c JOIN users u ON u.id=c.recipient_id WHERE c.tenant_id=? ORDER BY c.created_at DESC,c.id DESC LIMIT 50',[$tenant])->fetchAll(PDO::FETCH_ASSOC);
  return ['items'=>array_map(fn($c)=>$this->visible($c,$now),$rows),'contacts'=>$this->contacts($tenant,$actor),'purposes'=>self::PURPOSES,'daily_limit'=>(int)$config['daily_limit'],'used_today'=>$this->used($tenant,$now)];
 }
 public function create(int $tenant,int $actor,int $agent,int $recipient,string $id,string $purpose,string $objective,string $boundaries,int $now):array {
  $this->id($id);$objective=$this->text($objective,1200);$boundaries=$this->text($boundaries,1200);
  if(!isset(self::PURPOSES[$purpose]))throw new InvalidArgumentException('Motivo inválido.');
  return $this->core->transaction(function()use($tenant,$actor,$agent,$recipient,$id,$purpose,$objective,$boundaries,$now){
   $this->core->lock($tenant);$this->owner($tenant,$actor);
   $hash=hash('sha256',Core::json([$tenant,$actor,$agent,$recipient,$purpose,$objective,$boundaries]));
   $old=$this->core->query('SELECT * FROM ai_app_calls WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
   if($old){if($old['request_hash']!==$hash)throw new RuntimeException('Solicitud modificada.',409);return $this->visible($old,$now);}
   $ids=array_map('intval',array_column($this->contacts($tenant,$actor),'id'));
   if(!in_array($recipient,$ids,true))throw new RuntimeException('Primero agrega este usuario a tus chats.',403);
   $a=$this->core->query('SELECT id,name,module FROM ai_agents WHERE id=? AND tenant_id=?',[$agent,$tenant])->fetch(PDO::FETCH_ASSOC);
   if(!$a)throw new RuntimeException('Agente no disponible.',404);
   $modules=['support'=>['support'],'hr'=>['hr'],'advertising'=>['marketing','sales'],'negotiation'=>['sales','logistics','commerce','reservations','operations'],'onboarding'=>['sales','support']];
   if(!in_array($a['module'],$modules[$purpose],true))throw new InvalidArgumentException('Elige un agente adecuado al motivo.');
   $day=(int)(floor($now/86400)*86400);
   if((int)$this->core->query('SELECT COUNT(*) FROM ai_app_calls WHERE tenant_id=? AND created_at>=?',[$tenant,$day])->fetchColumn()>=10)throw new RuntimeException('Límite del piloto: 10 invitaciones por día.',429);
   if($this->core->query("SELECT id FROM ai_app_calls WHERE tenant_id=? AND recipient_id=? AND ((status='invited' AND expires_at>?) OR (status='active' AND (expires_at=0 OR expires_at>?)))",[$tenant,$recipient,$now,$now])->fetchColumn())throw new RuntimeException('Este usuario ya tiene una llamada pendiente o aceptada.',409);
   if($this->core->query("SELECT id FROM ai_app_calls WHERE tenant_id=? AND recipient_id=? AND status='declined' AND created_at>=?",[$tenant,$recipient,$day])->fetchColumn())throw new RuntimeException('El usuario rechazó una llamada hoy. No se enviará otra hoy.',409);
   if($this->core->query('SELECT id FROM ai_app_calls WHERE tenant_id=? AND recipient_id=? AND created_at>?',[$tenant,$recipient,$now-60])->fetchColumn())throw new RuntimeException('Espera un minuto entre invitaciones al mismo usuario.',429);
   $this->core->query("INSERT INTO ai_app_calls(id,tenant_id,owner_id,recipient_id,agent_id,agent_name,purpose,objective,boundaries,status,created_at,expires_at,accepted_at,request_hash) VALUES(?,?,?,?,?,?,?,?,?,'invited',?,?,0,?)",[$id,$tenant,$actor,$recipient,$agent,$a['name'],$purpose,$objective,$boundaries,$now,$now+3600,$hash]);
   $this->core->audit($tenant,$actor,'app_call.invited',$id,['status'=>'invited'],$now);
   return $this->visible($this->row($id),$now);
  });
 }
 public function inbox(int $actor,int $now):array {
  $rows=$this->core->query("SELECT c.id,c.agent_name,c.purpose,c.objective,c.status,c.expires_at,t.name AS company FROM ai_app_calls c JOIN ai_tenants t ON t.id=c.tenant_id WHERE c.recipient_id=? AND c.status IN ('invited','active') AND (c.expires_at>? OR (c.status='active' AND c.expires_at=0)) ORDER BY c.created_at,c.id LIMIT 10",[$actor,$now])->fetchAll(PDO::FETCH_ASSOC);return ['items'=>$rows];
 }
 public function detail(string $id,int $actor,int $now):array {
  $c=$this->row($id);$this->participant($c,$actor);$c=$this->visible($c,$now);
  if((int)$c['recipient_id']===$actor)unset($c['boundaries']);
  $turns=$this->core->query('SELECT id,message,reply,status,created_at FROM ai_app_call_turns WHERE call_id=? ORDER BY created_at,id',[$id])->fetchAll(PDO::FETCH_ASSOC);
  $voice=$this->core->query('SELECT role,text,interrupted,created_at FROM ai_app_voice_events WHERE call_id=? ORDER BY created_at,id',[$id])->fetchAll(PDO::FETCH_ASSOC);
  return ['call'=>$c,'turns'=>$turns,'voice_events'=>$voice];
 }
 public function action(string $id,int $actor,string $action,int $now):array {
  return $this->core->transaction(function()use($id,$actor,$action,$now){
   $c=$this->row($id);$this->core->lock((int)$c['tenant_id']);$c=$this->row($id);$this->participant($c,$actor);
   if(!in_array($action,['accept','decline','end'],true))throw new InvalidArgumentException('Acción inválida.');
   if($action!=='end'&&(int)$c['recipient_id']!==$actor)throw new RuntimeException('Solo el destinatario puede aceptar.',403);
   $current=$this->visible($c,$now)['status'];
   if($action==='accept'){
    if($current==='active')return $this->detail($id,$actor,$now);
    if($current!=='invited')throw new RuntimeException('Invitación cerrada o vencida.',409);
    $this->owner((int)$c['tenant_id'],(int)$c['owner_id']);
    $this->core->query('SELECT id FROM users WHERE id=?'.$this->core->lockSuffix(),[$actor])->fetchColumn();
    if($this->core->query("SELECT id FROM ai_app_calls WHERE recipient_id=? AND status='active' AND (expires_at>? OR expires_at=0)",[$actor,$now])->fetchColumn())throw new RuntimeException('Ya tienes una conversación activa.',409);
    $this->core->query("UPDATE ai_app_calls SET status='active',accepted_at=?,expires_at=? WHERE id=?",[$now,0,$id]);
   }else{
    if(in_array($current,['invited','active'],true))$this->core->query('UPDATE ai_app_calls SET status=? WHERE id=?',[$action==='decline'?'declined':'ended',$id]);
   }
   return $this->detail($id,$actor,$now);
  });
 }
 public function turn(string $id,int $actor,string $turn,string $message,int $now):array {
  $this->id($turn);$message=$this->text($message,2000);
  $prepared=$this->core->transaction(function()use($id,$actor,$turn,$message,$now){
   $c=$this->row($id);$this->core->lock((int)$c['tenant_id']);$c=$this->row($id);
   if((int)$c['recipient_id']!==$actor)throw new RuntimeException('Solo el destinatario puede conversar.',403);
   $existing=$this->core->query('SELECT * FROM ai_app_call_turns WHERE id=?',[$turn])->fetch(PDO::FETCH_ASSOC);
   if($existing){if($existing['call_id']!==$id||$existing['message']!==$message)throw new RuntimeException('Turno modificado.',409);return ['existing'=>true];}
   if($this->visible($c,$now)['status']!=='active')throw new RuntimeException('La conversación ha terminado.',409);
   $config=$this->owner((int)$c['tenant_id'],(int)$c['owner_id']);
   $history=$this->core->query('SELECT message,reply,status FROM ai_app_call_turns WHERE call_id=? ORDER BY created_at,id',[$id])->fetchAll(PDO::FETCH_ASSOC);
   // A timed-out request remains reserved; do not spend again on an uncertain result.
   foreach($history as $h)if($h['status']==='running')throw new RuntimeException('Hay una respuesta en proceso. Actualiza el resultado.',409);
   if($this->used((int)$c['tenant_id'],$now)>=(int)$config['daily_limit'])throw new RuntimeException('Se agotó la cuota diaria compartida de IA.',429);
   $a=$this->core->query('SELECT name,module,instructions FROM ai_agents WHERE id=? AND tenant_id=?',[$c['agent_id'],$c['tenant_id']])->fetch(PDO::FETCH_ASSOC);
   if(!$a||strlen($a['instructions'])>3000)throw new RuntimeException('Revisa la configuración del agente.',409);
   $profile=(new CompanyProfile($this->core))->read((int)$c['tenant_id'],(int)$c['owner_id']);
   $this->core->query("INSERT INTO ai_app_call_turns(id,call_id,tenant_id,message,status,created_at) VALUES(?,?,?,?,'running',?)",[$turn,$id,$c['tenant_id'],$message,$now]);
   return compact('c','a','profile','history');
  });
  if(isset($prepared['existing']))return $this->detail($id,$actor,$now);
  $c=$prepared['c'];$reply=null;$usage=null;$status='failed';
  try{
   $knowledge=[['id'=>'scope-approved','text'=>'Conversación aceptada con el agente de IA de la empresa. Motivo: '.self::PURPOSES[$c['purpose']].'. Objetivo: '.$c['objective']],['id'=>'agent-configuration','text'=>$prepared['a']['instructions']],['id'=>'company-profile','text'=>Core::json($prepared['profile']['fields'],4000)],['id'=>'call-boundaries','text'=>$c['boundaries']],['id'=>'agent-role','text'=>Core::json(AgentPreviewCatalog::profile($prepared['a']['module']),4000)]];
   if($c['purpose']==='onboarding')$knowledge[]=['id'=>'platform-tour','text'=>'Recorrido documentado de TuaniChat: chats individuales entre usuarios. En el panel empresarial hay Información para la ficha del negocio; Agentes para configurar funciones como Soporte, Ventas y Marketing; Tareas para preparar y revisar borradores; Atención para consultar respuestas y tickets de soporte. Llamadas ofrece esta prueba de conversación de IA por turnos con invitaciones aceptadas. El acceso a cada opción depende de la cuenta y sus permisos. Pregunta qué pantalla ve la persona y qué objetivo tiene; no ves ni controlas su pantalla. No todos los usuarios son propietarios de empresa. No hay en este piloto publicación automática de publicidad, envío automático de SMS ni telefonía masiva. No inventes menús, enlaces ni procesos de compra.'];
   $memory=array_map(fn($h)=>['user'=>mb_substr($h['message'],0,500),'assistant'=>mb_substr($h['reply']??'',0,500)],array_slice($prepared['history'],-2));
   $result=($this->generate)(['id'=>$turn,'tenant_id'=>(int)$c['tenant_id'],'actor_id'=>$actor,'agent_id'=>(int)$c['agent_id']],$message,$knowledge,$memory,'general');
   $p=SupportContract::proposal(json_encode($result['proposal']??null,JSON_THROW_ON_ERROR),array_column($knowledge,'id'));
   $reply=$p['reply'];if($c['purpose']==='negotiation')$reply.="\nCualquier propuesta queda pendiente de aprobación de la empresa; esta conversación no confirma un acuerdo.";
   $status='answered';$usage=$result['usage']??null;
  }catch(\Throwable $e){/* Fail closed; provider details and credentials never enter the response. */}
  $this->core->transaction(function()use($id,$c,$turn,$reply,$usage,$status){
   $this->core->lock((int)$c['tenant_id']);$latest=$this->row($id);
   $authorized=true;try{$this->owner((int)$c['tenant_id'],(int)$c['owner_id']);}catch(\Throwable $e){$authorized=false;}
   if(!$authorized||$this->visible($latest,time())['status']!=='active'){$reply=null;$status='discarded';}
   $this->core->query("UPDATE ai_app_call_turns SET reply=?,status=?,usage_json=? WHERE id=? AND status='running'",[$reply,$status,$usage?Core::json($usage,2000):null,$turn]);
  });
  return $this->detail($id,$actor,time());
 }
}
