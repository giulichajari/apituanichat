<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use PDO;
use RuntimeException;
use InvalidArgumentException;

/** Executes only structured record writes explicitly submitted by a company member. */
final class RecordJobs
{
 public const EVENT='BUSINESS_RECORD_WRITE';
 public const MODULES=['customer'=>['sales','support','commerce'], 'opportunity'=>['sales'], 'appointment'=>['reservations'], 'finance'=>['finance'], 'shipment'=>['operations','logistics','commerce'],'directive'=>['director','operations'],'hr_task'=>['hr'],'campaign'=>['marketing'],'document'=>['director','sales','operations','support','marketing','reservations','commerce','logistics'],'inventory'=>['operations','commerce'],'order'=>['sales','commerce','operations']];
 public function __construct(private Core $core,private Events $events,private BusinessRecords $records){}
 private function validate(int $tenant,int $actor,int $agent,array $p):string{
  $keys=['id','kind','title','status','fields','version'];$actual=array_keys($p);sort($keys);sort($actual);
  if($actual!==$keys||!is_string($p['id'])||!preg_match('/^[a-f0-9]{32}$/D',$p['id'])||!is_string($p['kind'])||!isset(self::MODULES[$p['kind']])||!is_string($p['title'])||!is_string($p['status'])||!is_array($p['fields'])||!is_int($p['version'])||$p['version']<0)throw new InvalidArgumentException('Datos de tarea inválidos');
  $this->core->authorize($tenant,$actor,in_array($p['kind'],['finance','hr_task'],true)?'configure':'execute');
  $module=$this->core->query('SELECT module FROM ai_agents WHERE tenant_id=? AND id=?',[$tenant,$agent])->fetchColumn();
  if(!in_array($module,self::MODULES[$p['kind']],true))throw new RuntimeException('Elige un agente de la función indicada',403);
  if($p['kind']==='finance'&&$p['status']!=='draft')throw new InvalidArgumentException('El agente solo prepara borradores financieros');
  if(in_array($p['kind'],['directive','hr_task','campaign','document','inventory','order'],true)&&($p['version']!==0||$p['status']!==BusinessRecords::KINDS[$p['kind']][0]))throw new InvalidArgumentException('La herramienta crea registros iniciales; los cambios posteriores requieren revisión en Gestión');
  $this->records->validateRecord($p['kind'],$p['title'],$p['status'],$p['fields']);
  return $module;
 }
 public function enqueue(int $tenant,int $actor,int $agent,array $p,string $key,int $now,?string $parent=null):array{
  return $this->core->transaction(function()use($tenant,$actor,$agent,$p,$key,$now,$parent){
   $this->core->lock($tenant);$module=$this->validate($tenant,$actor,$agent,$p);
   if($parent!==null){
    if(!preg_match('/^[a-f0-9]{32}$/D',$parent))throw new InvalidArgumentException('Dependencia inválida');
    $before=$this->core->query('SELECT actor_id,event_type,status,payload_json FROM ai_events WHERE tenant_id=? AND id=?',[$tenant,$parent])->fetch(PDO::FETCH_ASSOC);
    if(!$before||(int)$before['actor_id']!==$actor||$before['event_type']!==self::EVENT)throw new RuntimeException('Tarea anterior no disponible',404);
    if(!in_array($before['status'],['queued','processing','succeeded'],true))throw new RuntimeException('La tarea anterior requiere revisión',409);
    $previous=json_decode($before['payload_json'],true,16,JSON_THROW_ON_ERROR);
    if(in_array(($previous['record']['kind']??''),['finance','hr_task'],true))$this->core->authorize($tenant,$actor,'configure');
   }
   return $this->events->enqueue($tenant,$actor,$agent,self::EVENT,['module'=>$module,'record'=>$p],$key,$now,$parent);
  });
 }
 public function listing(int $tenant,int $actor):array{
  $c=$this->core;$role=$c->authorize($tenant,$actor);$admin=in_array($role,['owner','admin'],true);
  // Operators see their own jobs only. Viewers cannot read submitted record payloads.
  if($role==='viewer')return ['jobs'=>[],'agents'=>[],'can_submit'=>false,'modules'=>self::MODULES];
  $rows=$c->query('SELECT id,agent_id,actor_id,parent_id,correlation_id,status,payload_json,result_json,error_code,created_at,updated_at FROM ai_events WHERE tenant_id=? AND event_type=?'.($admin?'':' AND actor_id=?').' ORDER BY created_at DESC,id DESC LIMIT 50',$admin?[$tenant,self::EVENT]:[$tenant,self::EVENT,$actor])->fetchAll(PDO::FETCH_ASSOC);
  $out=[];
  foreach($rows as $r){
   $p=json_decode($r['payload_json'],true,16,JSON_THROW_ON_ERROR);$record=$p['record']??[];
   if(!$admin&&in_array(($record['kind']??''),['finance','hr_task'],true))continue;
   unset($r['payload_json']);$r['kind']=$record['kind']??'';$r['title']=$record['title']??'';
   $r['result']=$r['result_json']?json_decode($r['result_json'],true,16,JSON_THROW_ON_ERROR):null;unset($r['result_json']);
   $r['can_depend']=(int)$r['actor_id']===$actor&&in_array($r['status'],['queued','processing','succeeded'],true);
   $r['parent_status']=$r['parent_id']?$c->query('SELECT status FROM ai_events WHERE tenant_id=? AND id=?',[$tenant,$r['parent_id']])->fetchColumn():null;
   $out[]=$r;
  }
  $agents=$c->query('SELECT id,name,module,enabled FROM ai_agents WHERE tenant_id=? ORDER BY id',[$tenant])->fetchAll(PDO::FETCH_ASSOC);
  $eligible=true;try{$c->entitlement($tenant,time());}catch(RuntimeException $e){if($e->getCode()!==402)throw $e;$eligible=false;}
  return ['jobs'=>$out,'agents'=>$agents,'can_submit'=>$eligible,'modules'=>self::MODULES];
 }
 public function once(callable $send,callable $clock,?int $onlyTenant=null):array{
  $row=$this->events->claim($clock(),[self::EVENT],$onlyTenant);if(!$row)return ['status'=>'idle'];
  $c=$this->core;$tenant=(int)$row['tenant_id'];$actor=(int)$row['actor_id'];$agent=(int)$row['agent_id'];$id=$row['id'];$lease=$row['lease_key'];
  try{
   $payload=json_decode($row['payload_json'],true,16,JSON_THROW_ON_ERROR);$p=$payload['record']??[];
   $module=$this->validate($tenant,$actor,$agent,$p);
   if($module!==($payload['module']??null))throw new RuntimeException('Configuración modificada',409);
   $request=['schema_version'=>1,'event_id'=>$id,'tenant_id'=>$tenant,'actor_id'=>$actor,'agent_id'=>$agent,'event_type'=>strtoupper($module).'_REQUEST','module'=>$module,'correlation_id'=>$row['correlation_id'],'payload'=>(object)[]];
   // n8n receives routing metadata only. The record stays in the company database.
   EnterpriseDispatch::validateReply($request,$send($request));
   $result=$c->transaction(function()use($c,$tenant,$actor,$agent,$p,$module,$id,$lease,$clock){
    $c->lock($tenant);$now=$clock();
    if($this->validate($tenant,$actor,$agent,$p)!==$module)throw new RuntimeException('Configuración modificada',409);
    if($p['version']===0&&$c->query('SELECT id FROM ai_business_records WHERE tenant_id=? AND id=?',[$tenant,$p['id']])->fetchColumn())throw new RuntimeException('El registro ya fue creado; revisa su versión',409);
    // Local write and event completion share a single transaction. A lost lease or
    // changed permission causes both operations to roll back, including audit rows.
    $result=$this->records->save($tenant,$actor,$p['id'],$p['kind'],$p['title'],$p['status'],$p['fields'],$p['version'],$now);
    (new AgentContext($c))->result($tenant,$actor,$id,$result['id'],$p['kind'],$module,$now);
    $this->events->finish($tenant,$id,$lease,['outcome'=>'record_saved','record_id'=>$result['id'],'kind'=>$p['kind'],'version'=>$result['version'],'module'=>$module,'business_action_executed'=>true,'external_actions'=>0],$now);
    return $result;
   });
   return ['status'=>'record_saved','event_id'=>$id,'record_id'=>$result['id']];
  }catch(\Throwable $error){
   try{$this->events->fail($tenant,$id,$lease,$clock(),true);}catch(\Throwable $ignored){return ['status'=>'reconciliation_pending','event_id'=>$id];}
   return ['status'=>'needs_review','event_id'=>$id];
  }
 }
}
