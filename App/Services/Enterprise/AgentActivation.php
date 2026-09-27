<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use PDO;
use RuntimeException;
use InvalidArgumentException;
final class AgentActivation {
 public function __construct(private Core $core){}
 private function row(int $tenant,int $agent):array{
  $r=$this->core->query('SELECT id,name,module,instructions,enabled FROM ai_agents WHERE tenant_id=? AND id=?',[$tenant,$agent])->fetch(PDO::FETCH_ASSOC);
  if(!$r)throw new RuntimeException('Agente no disponible',404);return $r;
 }
 private static function digest(array $r):string{return hash('sha256',Core::json([(int)$r['id'],$r['name'],$r['module'],$r['instructions'],(int)$r['enabled']]));}
 private function platformAdmin(int $actor):bool{return strtoupper((string)$this->core->query('SELECT rol FROM users WHERE id=?',[$actor])->fetchColumn())==='ADMIN';}
 private function quota(int $tenant,int $now):array{
  $limit=$this->core->query("SELECT limit_units FROM ai_limits WHERE tenant_id=? AND metric='executions'",[$tenant])->fetchColumn();
  $used=(int)$this->core->query("SELECT units FROM ai_usage WHERE tenant_id=? AND metric='executions' AND period=?",[$tenant,gmdate('Y-m',$now)])->fetchColumn();
  return ['limit'=>$limit===false?null:(int)$limit,'used'=>$used,'remaining'=>$limit===false?0:max(0,(int)$limit-$used),'period'=>gmdate('Y-m',$now)];
 }
 public function listing(int $tenant,int $actor,int $now):array{
  $role=$this->core->authorize($tenant,$actor);$valid=true;
  try{$this->core->entitlement($tenant,$now);}catch(RuntimeException $e){if($e->getCode()!==402)throw $e;$valid=false;}
  $rows=$this->core->query('SELECT id,name,module,instructions,enabled FROM ai_agents WHERE tenant_id=? ORDER BY id',[$tenant])->fetchAll(PDO::FETCH_ASSOC);
  $agents=[];foreach($rows as $r)$agents[]=['id'=>(int)$r['id'],'name'=>$r['name'],'module'=>$r['module'],'enabled'=>(int)$r['enabled']===1,'digest'=>self::digest($r),'tools'=>array_keys(array_filter(RecordJobs::MODULES,fn($modules)=>in_array($r['module'],$modules,true)))];
  return ['agents'=>$agents,'subscription_valid'=>$valid,'can_manage'=>in_array($role,['owner','admin'],true),'can_manage_quota'=>$this->platformAdmin($actor)&&in_array($role,['owner','admin'],true),'quota'=>$this->quota($tenant,$now)];
 }
 public function toggle(int $tenant,int $actor,int $agent,bool $enabled,string $expected,int $now):void{
  $this->core->transaction(function()use($tenant,$actor,$agent,$enabled,$expected,$now){
   $c=$this->core;$c->lock($tenant);$c->authorize($tenant,$actor,'configure');$r=$this->row($tenant,$agent);
   if(!hash_equals(self::digest($r),$expected))throw new RuntimeException('El agente cambió. Actualiza su estado antes de continuar.',409);
   if($enabled){
    $c->entitlement($tenant,$now);
    if(!array_filter(RecordJobs::MODULES,fn($modules)=>in_array($r['module'],$modules,true)))throw new RuntimeException('Esta función todavía no dispone de ejecución de registros internos.',409);
    if($this->quota($tenant,$now)['remaining']<1)throw new RuntimeException('Falta cuota de ejecuciones disponible para este mes.',429);
   }
   if((int)$r['enabled']===($enabled?1:0))return;
   $c->query('UPDATE ai_agents SET enabled=? WHERE tenant_id=? AND id=?',[$enabled?1:0,$tenant,$agent]);
   $c->audit($tenant,$actor,'agent.activation_changed',(string)$agent,['status'=>$enabled?'enabled':'disabled','module'=>$r['module']],$now);
  });
 }
 public function setQuota(int $tenant,int $actor,int $limit,?int $expected,int $now):void{
  if($limit<0||$limit>1000000)throw new InvalidArgumentException('Cuota fuera de rango (0 a 1000000).');
  $this->core->transaction(function()use($tenant,$actor,$limit,$expected,$now){
   $c=$this->core;$c->lock($tenant);$c->authorize($tenant,$actor,'configure');
   if(!$this->platformAdmin($actor))throw new RuntimeException('Solo administración de la plataforma asigna cuotas.',403);
   $current=$this->quota($tenant,$now)['limit'];if($current!==$expected)throw new RuntimeException('La cuota cambió. Actualiza antes de guardarla.',409);
   if($current===$limit)return;
   if($current===null)$c->query("INSERT INTO ai_limits(tenant_id,metric,limit_units) VALUES(?,'executions',?)",[$tenant,$limit]);
   else $c->query("UPDATE ai_limits SET limit_units=? WHERE tenant_id=? AND metric='executions'",[$limit,$tenant]);
   $c->audit($tenant,$actor,'quota.limit_changed','executions',['metric'=>'executions','units'=>$limit],$now);
  });
 }
}
