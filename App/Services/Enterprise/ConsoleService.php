<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use PDO;
use RuntimeException;
final class ConsoleService
{
 public const MODULES=['director'=>'Dirección','sales'=>'Ventas','finance'=>'Finanzas','operations'=>'Operaciones','hr'=>'Recursos humanos','support'=>'Soporte','marketing'=>'Marketing y compras','reservations'=>'Reservas y citas','commerce'=>'Restaurantes y comercio','logistics'=>'Transporte y logística'];
 public function __construct(private Core $core){}
 public function dashboard(int $tenant,int $actor,int $now):array{
  $c=$this->core;$role=$c->authorize($tenant,$actor);
  $company=$c->query('SELECT id,name FROM ai_tenants WHERE id=?',[$tenant])->fetch(PDO::FETCH_ASSOC);
  $subscription=$c->query('SELECT s.status,s.valid_until,p.name,p.price_cents,p.currency,p.call_price_cents,p.version AS price_version FROM ai_subscriptions s JOIN ai_plans p ON p.code=s.plan_code WHERE s.tenant_id=?',[$tenant])->fetch(PDO::FETCH_ASSOC);
  $internal_access=$c->internalAccess($tenant);
  $agents=$c->query('SELECT id,name,module,instructions,enabled FROM ai_agents WHERE tenant_id=? ORDER BY id',[$tenant])->fetchAll(PDO::FETCH_ASSOC);
  $linked=(int)$c->query('SELECT enterprise_agent_id FROM support_company_bindings WHERE tenant_id=?',[$tenant])->fetchColumn();
  foreach($agents as &$agent){$agent['support_linked']=(int)$agent['id']===$linked;$agent['support_role_mismatch']=$agent['support_linked']&&$agent['module']!=='support';}unset($agent);
  $members=$c->query('SELECT m.user_id,m.role,m.status,u.email FROM ai_members m JOIN users u ON u.id=m.user_id WHERE m.tenant_id=? ORDER BY m.user_id',[$tenant])->fetchAll(PDO::FETCH_ASSOC);
  $usage=$c->query('SELECT l.metric,l.limit_units,COALESCE(u.units,0) AS used_units FROM ai_limits l LEFT JOIN ai_usage u ON u.tenant_id=l.tenant_id AND u.metric=l.metric AND u.period=? WHERE l.tenant_id=? ORDER BY l.metric',[gmdate('Y-m',$now),$tenant])->fetchAll(PDO::FETCH_ASSOC);
  $events=$c->query('SELECT id,agent_id,event_type,status,error_code,created_at,updated_at FROM ai_events WHERE tenant_id=? ORDER BY created_at DESC,id DESC LIMIT 50',[$tenant])->fetchAll(PDO::FETCH_ASSOC);
  $approvals=$c->query('SELECT id,event_id,tool,status,expires_at,requested_by,decided_by,created_at FROM ai_approvals WHERE tenant_id=? ORDER BY created_at DESC,id DESC LIMIT 50',[$tenant])->fetchAll(PDO::FETCH_ASSOC);
  $audit=$c->query('SELECT id,actor_id,action,object_id,metadata_json,created_at FROM ai_audit WHERE tenant_id=? ORDER BY id DESC LIMIT 50',[$tenant])->fetchAll(PDO::FETCH_ASSOC);
  $metrics=$c->query("SELECT status,COUNT(*) AS total FROM ai_events WHERE tenant_id=? GROUP BY status",[$tenant])->fetchAll(PDO::FETCH_ASSOC);
  // No secret values, event payloads or unrequested customer memories in dashboard responses.
  return compact('metrics','internal_access','company','role','subscription','agents','members','usage','events','approvals','audit')+['can_manage_plan'=>strtoupper((string)$c->query('SELECT rol FROM users WHERE id=?',[$actor])->fetchColumn())==='ADMIN','modules'=>self::MODULES,'connections'=>['voice'=>'not_configured','billing'=>'not_configured','enterprise_execution'=>'not_connected']];
 }
 public function member(int $tenant,int $actor,string $email,string $role,bool $active,int $now):void{
  $this->core->authorize($tenant,$actor,'members');
  if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new \InvalidArgumentException('Correo inválido');
  $user=$this->core->query('SELECT id FROM users WHERE email=?',[$email])->fetchColumn();
  if(!$user)throw new RuntimeException('La persona debe tener una cuenta de TuaniChat.',404);
  $this->core->setMember($tenant,$actor,(int)$user,$role,$active,$now,fn($id)=>(bool)$this->core->query('SELECT id FROM users WHERE id=?',[$id])->fetchColumn());
 }
 public function agent(int $tenant,int $actor,?int $id,string $name,string $module,string $instructions,int $now):int{
  // Configuration is usable before billing exists; execution is not falsely enabled.
  return $this->core->transaction(function()use($tenant,$actor,$id,$name,$module,$instructions,$now){
   $c=$this->core;$c->lock($tenant);$c->authorize($tenant,$actor,'configure');
   $linked=(int)$c->query('SELECT enterprise_agent_id FROM support_company_bindings WHERE tenant_id=?',[$tenant])->fetchColumn();
   if($id&&$id===$linked&&$module!=='support')throw new \InvalidArgumentException('El agente conectado a TuaniBot debe conservar la función Soporte. Crea otro agente para la nueva función.');
   $current=$id?$c->query('SELECT enabled,module FROM ai_agents WHERE tenant_id=? AND id=?',[$tenant,$id])->fetch(PDO::FETCH_ASSOC):null;
   // Editing the same function preserves an explicit activation. Changing function pauses it.
   $enabled=$current&&(int)$current['enabled']===1&&$current['module']===$module;
   return $c->saveAgent($tenant,$actor,$id,$name,$module,$instructions,$enabled,$now);
  });
 }
 public function price(int $actor,int $monthly,int $perCall,int $version,int $now):void{
  if($monthly<1||$monthly>100000000||$perCall<1||$perCall>100000||$version<1)throw new \InvalidArgumentException('Precio inválido');
  $this->core->transaction(function()use($actor,$monthly,$perCall,$version,$now){
   $c=$this->core;if(strtoupper((string)$c->query('SELECT rol FROM users WHERE id=?'.$c->lockSuffix(),[$actor])->fetchColumn())!=='ADMIN')throw new RuntimeException('Solo administración de plataforma',403);
   $old=$c->query("SELECT version FROM ai_plans WHERE code='business'".$c->lockSuffix())->fetchColumn();if((int)$old!==$version)throw new RuntimeException('Precio modificado',409);
   $c->query("UPDATE ai_plans SET price_cents=?,call_price_cents=?,version=version+1 WHERE code='business'",[$monthly,$perCall]);
   $c->audit(0,$actor,'plan.price_updated','business',['version'=>$version+1],$now);
  });
 }
}
