<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use PDO;
use RuntimeException;
use InvalidArgumentException;
final class Events
{
 public function __construct(private Core $core){}
 public function enqueue(int $tenant,int $actor,int $agent,string $type,array $payload,string $key,int $now,?string $parent=null): array{
  if(!preg_match('/^[A-Z][A-Z0-9_]{2,63}$/D',$type)||!preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$key))throw new InvalidArgumentException('Evento inválido');
  $json=Core::json($payload);$digest=hash('sha256',Core::json([$actor,$agent,$type,$payload,$parent],20000));
  return $this->core->transaction(function()use($tenant,$actor,$agent,$type,$json,$digest,$key,$now,$parent){
   $c=$this->core;$c->lock($tenant);$c->authorize($tenant,$actor,'execute');
   $existing=$c->query('SELECT * FROM ai_events WHERE tenant_id=? AND request_key=?',[$tenant,$key])->fetch(PDO::FETCH_ASSOC);
   if($existing){if(!hash_equals($existing['request_hash'],$digest))throw new RuntimeException('Clave reutilizada con otro evento',409);return $this->publicEvent($existing);}
   $c->entitlement($tenant,$now);
   if(!$c->query('SELECT id FROM ai_agents WHERE tenant_id=? AND id=? AND enabled=1',[$tenant,$agent])->fetchColumn())throw new RuntimeException('Agente no disponible',404);
   $id=bin2hex(random_bytes(16));$correlation=$id;
   if($parent){$row=$c->query('SELECT correlation_id,actor_id FROM ai_events WHERE tenant_id=? AND id=?',[$tenant,$parent])->fetch(PDO::FETCH_ASSOC);if(!$row||(int)$row['actor_id']!==$actor)throw new RuntimeException('Evento padre no disponible',404);$correlation=$row['correlation_id'];}
   $c->chargeQuota($tenant,'executions',1,$now);
   $c->query("INSERT INTO ai_events(id,tenant_id,actor_id,agent_id,event_type,payload_json,request_key,request_hash,correlation_id,parent_id,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,'queued',?,?)",[$id,$tenant,$actor,$agent,$type,$json,$key,$digest,$correlation,$parent,$now,$now]);
   $c->audit($tenant,$actor,'event.queued',$id,['status'=>'queued'],$now);return $this->get($tenant,$actor,$id);
  });
 }
 private function publicEvent(array $row): array{unset($row['lease_key'],$row['request_hash'],$row['request_key']);return $row;}
 public function get(int $tenant,int $actor,string $id): array{
  $this->core->authorize($tenant,$actor);$row=$this->core->query('SELECT * FROM ai_events WHERE tenant_id=? AND id=?',[$tenant,$id])->fetch(PDO::FETCH_ASSOC);
  if(!$row)throw new RuntimeException('Evento no disponible',404);return $this->publicEvent($row);
 }
 /** Internal worker boundary, never expose this method as an unauthenticated endpoint. */
 public function claim(int $now,?array $types=null,?int $onlyTenant=null): ?array{
  $filter='';$args=[$now,$now];
  // Pending children do not monopolize the queue ahead of runnable root events.
  $dependency=" AND (parent_id IS NULL OR NOT EXISTS (SELECT 1 FROM ai_events parent WHERE parent.tenant_id=e.tenant_id AND parent.id=e.parent_id AND parent.status IN ('queued','processing'))) ";
  if($types!==null){
   if(!$types||count($types)>20)throw new InvalidArgumentException('Tipos inválidos');
   foreach($types as $type)if(!is_string($type)||!preg_match('/^[A-Z][A-Z0-9_]{2,63}$/D',$type))throw new InvalidArgumentException('Tipo inválido');
   $filter=' AND event_type IN ('.implode(',',array_fill(0,count($types),'?')).')';$args=array_merge($args,$types);
  }
  if($onlyTenant!==null){if($onlyTenant<1)throw new InvalidArgumentException('Empresa inválida');$filter.=' AND tenant_id=?';$args[]=$onlyTenant;}
  $candidate=$this->core->query("SELECT tenant_id,id FROM ai_events e WHERE (status='queued' OR (status='processing' AND lease_until<=?)) AND next_attempt_at<=? ".$dependency.$filter." ORDER BY created_at,id LIMIT 1",$args)->fetch(PDO::FETCH_ASSOC);
  if(!$candidate)return null;
  return $this->core->transaction(function()use($now,$candidate){
   $c=$this->core;
   $row=$candidate;$tenant=(int)$row['tenant_id'];$c->lock($tenant);
   // Re-read after the tenant lock. A concurrent worker may already hold the event.
   $row=$c->query('SELECT * FROM ai_events WHERE tenant_id=? AND id=?'.$c->lockSuffix(),[$tenant,$row['id']])->fetch(PDO::FETCH_ASSOC);
   if(!$row||!in_array($row['status'],['queued','processing'],true)||(int)$row['next_attempt_at']>$now||($row['status']==='processing'&&(int)$row['lease_until']>$now))return null;
   // A worker timeout cannot establish whether a remote action completed.
   if($row['status']==='processing'){$this->block($row,'lease_expired_unknown',$now,'needs_review');return null;}
   if($row['parent_id']!==null){
    $parent=$c->query('SELECT status,actor_id FROM ai_events WHERE tenant_id=? AND id=?',[$tenant,$row['parent_id']])->fetch(PDO::FETCH_ASSOC);
    if(!$parent||(int)$parent['actor_id']!==(int)$row['actor_id']){$this->block($row,'dependency_missing',$now,'blocked');return null;}
    if(in_array($parent['status'],['queued','processing'],true))return null;
    if($parent['status']!=='succeeded'){$this->block($row,'dependency_failed',$now,'blocked');return null;}
   }
   if((int)$row['attempts']>=5){$this->block($row,'retry_limit',$now,'failed');return null;}
   try{$c->authorize($tenant,(int)$row['actor_id'],'execute');$c->entitlement($tenant,$now);
    if(!$c->query('SELECT id FROM ai_agents WHERE tenant_id=? AND id=? AND enabled=1',[$tenant,$row['agent_id']])->fetchColumn())throw new RuntimeException('Agente pausado',403);
   }catch(RuntimeException $e){if(!in_array($e->getCode(),[402,403],true))throw $e;$this->block($row,'access_changed',$now,'blocked');return null;}
   $lease=bin2hex(random_bytes(32));$c->query("UPDATE ai_events SET status='processing',attempts=attempts+1,lease_key=?,lease_until=?,updated_at=? WHERE tenant_id=? AND id=?",[$lease,$now+90,$now,$tenant,$row['id']]);
   $row['status']='processing';$row['attempts']=(int)$row['attempts']+1;$row['lease_key']=$lease;$row['lease_until']=$now+90;
   $c->audit($tenant,0,'event.claimed',$row['id'],['attempt'=>$row['attempts']],$now);return $row;
  });
 }
 private function block(array $row,string $reason,int $now,string $status): void{
  $this->core->query('UPDATE ai_events SET status=?,error_code=?,lease_key=NULL,lease_until=0,updated_at=? WHERE tenant_id=? AND id=?',[$status,$reason,$now,$row['tenant_id'],$row['id']]);
  $this->core->audit((int)$row['tenant_id'],0,'event.blocked',$row['id'],['reason'=>$reason,'status'=>$status],$now);
 }
 public function finish(int $tenant,string $id,string $lease,array $result,int $now): void{
  $json=Core::json($result);
  $this->core->transaction(function()use($tenant,$id,$lease,$json,$now){
   $c=$this->core;$c->lock($tenant);$row=$c->query('SELECT * FROM ai_events WHERE tenant_id=? AND id=?',[$tenant,$id])->fetch(PDO::FETCH_ASSOC);
   if(!$row||!is_string($row['lease_key'])||!hash_equals($row['lease_key'],$lease))throw new RuntimeException('Lease inválido',409);
   if($row['status']==='succeeded'){if($row['result_json']!==$json)throw new RuntimeException('Resultado contradictorio',409);return;}
   if($row['status']!=='processing'||(int)$row['lease_until']<=$now)throw new RuntimeException('Lease vencido',409);
   if($row['parent_id']!==null){
    $parent=$c->query('SELECT status,actor_id FROM ai_events WHERE tenant_id=? AND id=?',[$tenant,$row['parent_id']])->fetch(PDO::FETCH_ASSOC);
    if(!$parent||$parent['status']!=='succeeded'||(int)$parent['actor_id']!==(int)$row['actor_id'])throw new RuntimeException('Dependencia no completada',409);
   }
   $c->authorize($tenant,(int)$row['actor_id'],'execute');$c->entitlement($tenant,$now);
   if(!$c->query('SELECT id FROM ai_agents WHERE tenant_id=? AND id=? AND enabled=1',[$tenant,$row['agent_id']])->fetchColumn())throw new RuntimeException('Agente pausado',403);
   $c->query("UPDATE ai_events SET status='succeeded',result_json=?,updated_at=? WHERE tenant_id=? AND id=?",[$json,$now,$tenant,$id]);
   $c->audit($tenant,0,'event.succeeded',$id,['status'=>'succeeded'],$now);
  });
 }
 public function fail(int $tenant,string $id,string $lease,int $now,bool $uncertain=false): void{
  $this->core->transaction(function()use($tenant,$id,$lease,$now,$uncertain){
   $c=$this->core;$c->lock($tenant);$row=$c->query('SELECT * FROM ai_events WHERE tenant_id=? AND id=?',[$tenant,$id])->fetch(PDO::FETCH_ASSOC);
   if(!$row||$row['status']!=='processing'||!is_string($row['lease_key'])||!hash_equals($row['lease_key'],$lease)||(int)$row['lease_until']<=$now)throw new RuntimeException('Lease inválido',409);
   $status=$uncertain?'needs_review':((int)$row['attempts']>=5?'failed':'queued');
   $c->query('UPDATE ai_events SET status=?,error_code=?,next_attempt_at=?,lease_key=NULL,lease_until=0,updated_at=? WHERE tenant_id=? AND id=?',[$status,$uncertain?'external_result_unknown':'execution_failed',$now+min(300,2**(int)$row['attempts']),$now,$tenant,$id]);
   $c->audit($tenant,0,'event.failed',$id,['status'=>$status],$now);
  });
 }
}
