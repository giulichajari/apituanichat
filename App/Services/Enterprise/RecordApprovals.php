<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use PDO;
use RuntimeException;
use InvalidArgumentException;
/** Two-person review of existing internal drafts. Never calls a payment or messaging provider. */
final class RecordApprovals
{
 public const TOOL='record.finalize_internal';
 public const EVENT='INTERNAL_RECORD_APPROVAL';
 private const TARGETS=['finance'=>'recorded','campaign'=>'reviewed','document'=>'reviewed'];
 public function __construct(private Core $core){}
 private function access(int $tenant,int $actor,int $now):void{
  $this->core->authorize($tenant,$actor,'configure');$this->core->entitlement($tenant,$now);
 }
 private function agent(int $tenant,int $agent,string $kind):string{
  $a=$this->core->query('SELECT module,enabled FROM ai_agents WHERE tenant_id=? AND id=?',[$tenant,$agent])->fetch(PDO::FETCH_ASSOC);
  if(!$a||(int)$a['enabled']!==1||!in_array($a['module'],RecordJobs::MODULES[$kind]??[],true))throw new RuntimeException('Agente pausado o incompatible',409);
  return $a['module'];
 }
 public function request(int $tenant,int $actor,int $agent,string $record,int $version,int $now):array{
  if(!preg_match('/^[a-f0-9]{32}$/D',$record)||$version<1||$agent<1)throw new InvalidArgumentException('Guarda primero el borrador');
  return $this->core->transaction(function()use($tenant,$actor,$agent,$record,$version,$now){
   $c=$this->core;$c->lock($tenant);$this->access($tenant,$actor,$now);
   $r=$c->query('SELECT * FROM ai_business_records WHERE tenant_id=? AND id=?',[$tenant,$record])->fetch(PDO::FETCH_ASSOC);
   if(!$r)throw new RuntimeException('Registro no disponible',404);
   $key=hash('sha256',Core::json([self::EVENT,$actor,$agent,$record,$version]));
   // Stable key makes a lost HTTP response safe to retry, including after execution.
   $existing=$c->query('SELECT a.id,a.status,a.event_id FROM ai_approvals a JOIN ai_events e ON e.tenant_id=a.tenant_id AND e.id=a.event_id WHERE e.tenant_id=? AND e.request_key=? AND a.tool=?',[$tenant,$key,self::TOOL])->fetch(PDO::FETCH_ASSOC);
   if($existing)return $existing+['duplicate'=>true];
   if(!isset(self::TARGETS[$r['kind']])||$r['status']!=='draft'||(int)$r['version']!==$version)throw new RuntimeException('Actualiza la lista: se requiere un borrador sin cambios',409);
   $this->agent($tenant,$agent,$r['kind']);
   $args=['id'=>$record,'kind'=>$r['kind'],'title'=>$r['title'],'status'=>self::TARGETS[$r['kind']],'fields'=>json_decode($r['data_json'],true,16,JSON_THROW_ON_ERROR),'version'=>$version];
   $events=new Events($c);$event=$events->enqueue($tenant,$actor,$agent,self::EVENT,$args,$key,$now);
   // Hold before commit: the generic worker must never claim a review request.
   $c->query("UPDATE ai_events SET status='needs_review',error_code='awaiting_approval' WHERE tenant_id=? AND id=?",[$tenant,$event['id']]);
   $id=(new Approvals($c))->request($tenant,$actor,$event['id'],self::TOOL,$args,$now+86400,$now);
   return ['id'=>$id,'status'=>'pending','event_id'=>$event['id'],'duplicate'=>false];
  });
 }
 public function execute(int $tenant,int $actor,string $id,int $now):array{
  if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException('Aprobación inválida');
  return $this->core->transaction(function()use($tenant,$actor,$id,$now){
   $c=$this->core;$c->lock($tenant);$this->access($tenant,$actor,$now);
   $a=$c->query('SELECT * FROM ai_approvals WHERE tenant_id=? AND id=?',[$tenant,$id])->fetch(PDO::FETCH_ASSOC);
   if(!$a||$a['tool']!==self::TOOL||(int)$a['requested_by']!==$actor)throw new RuntimeException('Solicitud no disponible para ejecutar',403);
   $e=$c->query('SELECT * FROM ai_events WHERE tenant_id=? AND id=?',[$tenant,$a['event_id']])->fetch(PDO::FETCH_ASSOC);
   if(!$e||$e['event_type']!==self::EVENT||(int)$e['actor_id']!==$actor)throw new RuntimeException('Evento de revisión inválido',409);
   if($a['status']==='consumed'&&$e['status']==='succeeded')return ['result'=>json_decode($e['result_json'],true,16,JSON_THROW_ON_ERROR),'duplicate'=>true];
   if($e['status']!=='needs_review'||$e['payload_json']!==$a['arguments_json'])throw new RuntimeException('Los datos de revisión cambiaron',409);
   $p=json_decode($a['arguments_json'],true,16,JSON_THROW_ON_ERROR);
   if(!isset(self::TARGETS[$p['kind']??''])||$p['status']!==self::TARGETS[$p['kind']])throw new RuntimeException('Acción no admitida',409);
   $module=$this->agent($tenant,(int)$e['agent_id'],$p['kind']);
   return (new Approvals($c))->consume($tenant,$actor,$id,self::TOOL,$p,$now,function()use($c,$tenant,$actor,$p,$e,$now,$module){
    $r=$c->query('SELECT * FROM ai_business_records WHERE tenant_id=? AND id=?',[$tenant,$p['id']])->fetch(PDO::FETCH_ASSOC);
    if(!$r||$r['status']!=='draft'||$r['kind']!==$p['kind']||(int)$r['version']!==$p['version']||$r['title']!==$p['title']||$r['data_json']!==Core::json($p['fields'],8000))throw new RuntimeException('El borrador cambió; solicita una nueva revisión',409);
    $saved=(new BusinessRecords($c))->save($tenant,$actor,$p['id'],$p['kind'],$p['title'],$p['status'],$p['fields'],$p['version'],$now);
    $result=['record_id'=>$saved['id'],'version'=>$saved['version'],'kind'=>$p['kind'],'status'=>$p['status'],'external_actions'=>0];
    (new AgentContext($c))->result($tenant,$actor,$e['id'],$p['id'],$p['kind'],$module,$now);
    $c->query("UPDATE ai_events SET status='succeeded',result_json=?,error_code=NULL,updated_at=? WHERE tenant_id=? AND id=?",[Core::json($result),$now,$tenant,$e['id']]);
    $c->audit($tenant,$actor,'approval.record_executed',$e['id'],['status'=>'succeeded','version'=>$saved['version']],$now);
    return ['result'=>$result,'duplicate'=>false];
   });
  });
 }
 public function decorate(int $tenant,int $actor,array $rows,int $now):array{
  $this->core->authorize($tenant,$actor,'approve');
  foreach($rows as &$row){
   $row['can_execute']=$row['tool']===self::TOOL&&$row['status']==='approved'&&(int)$row['requested_by']===$actor&&(int)$row['expires_at']>$now;
   if($row['tool']===self::TOOL&&$row['status']==='consumed'){
    $v=$this->core->query("SELECT result_json FROM ai_events WHERE tenant_id=? AND id=? AND event_type=? AND status='succeeded'",[$tenant,$row['event_id'],self::EVENT])->fetchColumn();
    $row['execution_result']=$v?json_decode($v,true,16,JSON_THROW_ON_ERROR):null;
   }
  }unset($row);return $rows;
 }
}
