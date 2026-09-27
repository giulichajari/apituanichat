<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use PDO;
use RuntimeException;
final class InternalAccess {
 public function __construct(private Core $core){}
 public function configure(int $tenant,int $actor,int $now):array {
  return $this->core->transaction(function()use($tenant,$actor,$now){
   $c=$this->core;$c->lock($tenant);
   if($tenant!==2||$actor!==4||$c->authorize($tenant,$actor,'configure')!=='owner'||strtoupper((string)$c->query('SELECT rol FROM users WHERE id=?',[$actor])->fetchColumn())!=='ADMIN'||(int)$c->query('SELECT created_by FROM ai_tenants WHERE id=?',[$tenant])->fetchColumn()!==$actor)throw new RuntimeException('Empresa o propietario distinto del autorizado',403);
   $existing=$c->query('SELECT enabled FROM ai_internal_access WHERE tenant_id=?',[$tenant])->fetchColumn();
   if($existing!==false)throw new RuntimeException('El acceso ya fue configurado. Consulta el estado; no se repite la activación.',409);
   $sub=$c->query('SELECT * FROM ai_subscriptions WHERE tenant_id=?',[$tenant])->fetch(PDO::FETCH_ASSOC);
   if(!$sub||$sub['status']!=='paused'||(int)$sub['valid_until']!==0||!empty($sub['provider_subscription_id']))throw new RuntimeException('La suscripción cambió; revisar antes de conceder acceso interno',409);
   if((int)$c->query("SELECT COUNT(*) FROM ai_events WHERE tenant_id=? AND status IN ('queued','processing')",[$tenant])->fetchColumn()>0)throw new RuntimeException('Hay tareas pendientes. Revisarlas antes de activar.',409);
   $limit=$c->query("SELECT limit_units FROM ai_limits WHERE tenant_id=? AND metric='executions'",[$tenant])->fetchColumn();
   if($limit!==false)throw new RuntimeException('La cuota ya fue asignada; revisar antes de modificarla.',409);
   $c->query('INSERT INTO ai_internal_access(tenant_id,enabled,granted_by,created_at,updated_at) VALUES(?,1,?,?,?)',[$tenant,$actor,$now,$now]);
   $activation=new AgentActivation($c);$activation->setQuota($tenant,$actor,1000,null,$now);
   $count=0;
   foreach($activation->listing($tenant,$actor,$now)['agents'] as $a){
    if(!$a['tools']||$a['enabled'])continue;
    $activation->toggle($tenant,$actor,$a['id'],true,$a['digest'],$now);$count++;
   }
   $c->audit($tenant,$actor,'access.internal_granted',(string)$tenant,['status'=>'internal','units'=>1000],$now);
   return ['empresa'=>$tenant,'acceso'=>'interno','mensualidad_cobrada'=>0,'cuota_mensual'=>1000,'agentes_habilitados'=>$count,'ia_generada'=>0];
  });
 }
 public function revoke(int $tenant,int $actor,int $now):void {
  $this->core->transaction(function()use($tenant,$actor,$now){
   $c=$this->core;$c->lock($tenant);
   if($tenant!==2||$actor!==4||$c->authorize($tenant,$actor,'configure')!=='owner'||strtoupper((string)$c->query('SELECT rol FROM users WHERE id=?',[$actor])->fetchColumn())!=='ADMIN')throw new RuntimeException('Acceso denegado',403);
   $c->query('UPDATE ai_internal_access SET enabled=0,updated_at=? WHERE tenant_id=?',[$now,$tenant]);
   $c->audit($tenant,$actor,'access.internal_revoked',(string)$tenant,['status'=>'disabled'],$now);
  });
 }
}
