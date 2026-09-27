<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use PDO;
use RuntimeException;
use InvalidArgumentException;

/** Local domain core. No network calls, checkout activation or arbitrary tool execution. */
final class Core
{
 public function __construct(private PDO $db){}
 public function query(string $sql,array $args=[]): \PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
 public function lockSuffix(): string{return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';}
 public function transaction(callable $work): mixed{
  if($this->db->inTransaction())return $work();
  $this->db->beginTransaction();try{$value=$work();$this->db->commit();return $value;}catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 public function lock(int $tenant): void{
  if(!$this->db->inTransaction())throw new RuntimeException('Se requiere transacción');
  $suffix=$this->lockSuffix();
  if(!$this->query('SELECT id FROM ai_tenants WHERE id=?'.$suffix,[$tenant])->fetchColumn())throw new RuntimeException('Empresa no disponible',404);
 }
 public static function json(array $data,int $maximum=16000): string{
  $normalize=function($value)use(&$normalize){if(!is_array($value))return $value;if(!array_is_list($value))ksort($value,SORT_STRING);return array_map($normalize,$value);};
  $json=json_encode($normalize($data),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  if(strlen($json)>$maximum)throw new InvalidArgumentException('Contenido demasiado grande');return $json;
 }
 public static function name(string $value,int $max=120): string{$value=trim($value);if($value===''||strlen($value)>$max||preg_match('/[\x00-\x1f]/',$value))throw new InvalidArgumentException('Nombre inválido');return $value;}
 public function authorize(int $tenant,int $actor,string $permission='read'): string{
  $role=$this->query("SELECT role FROM ai_members WHERE tenant_id=? AND user_id=? AND status='active'",[$tenant,$actor])->fetchColumn();
  $map=['read'=>['owner','admin','operator','viewer'],'execute'=>['owner','admin','operator'],'configure'=>['owner','admin'],'approve'=>['owner','admin'],'members'=>['owner']];
  if($actor<1||!in_array($role,$map[$permission]??[],true))throw new RuntimeException('Acceso empresarial denegado',403);return $role;
 }
 public function internalAccess(int $tenant): bool{
  return (int)$this->query('SELECT enabled FROM ai_internal_access WHERE tenant_id=?',[$tenant])->fetchColumn()===1;
 }
 public function entitlement(int $tenant,int $now): array{
  if($this->internalAccess($tenant))return ['tenant_id'=>$tenant,'status'=>'internal','provider'=>'internal','valid_until'=>0];
  $row=$this->query('SELECT * FROM ai_subscriptions WHERE tenant_id=?',[$tenant])->fetch(PDO::FETCH_ASSOC);
  if(!$row||!in_array($row['status'],['active','trial'],true)||(int)$row['valid_until']<=$now)throw new RuntimeException('Suscripción sin vigencia',402);return $row;
 }
 public function audit(int $tenant,int $actor,string $action,string $object,array $metadata,int $now): void{
  $safe=array_intersect_key($metadata,array_flip(['status','role','module','metric','units','attempt','reason','version','digest','event_id']));
  $this->query('INSERT INTO ai_audit(tenant_id,actor_id,action,object_id,metadata_json,created_at) VALUES(?,?,?,?,?,?)',[$tenant,$actor,$action,$object,self::json($safe,2000),$now]);
 }
 public function createTenant(int $actor,string $name,int $now,callable $userExists): array{
  if($actor<1||!$userExists($actor))throw new RuntimeException('Usuario no disponible',403);$name=self::name($name);
  return $this->transaction(function()use($actor,$name,$now){
   $this->query('INSERT INTO ai_tenants(name,created_by,created_at) VALUES(?,?,?)',[$name,$actor,$now]);$id=(int)$this->db->lastInsertId();
   $this->query("INSERT INTO ai_members(tenant_id,user_id,role,status) VALUES(?,?,'owner','active')",[$id,$actor]);
   $this->query("INSERT INTO ai_subscriptions(tenant_id,plan_code,status,valid_until,version) VALUES(?,'business','paused',0,0)",[$id]);
   $this->audit($id,$actor,'tenant.created',(string)$id,['status'=>'paused'],$now);return ['id'=>$id,'name'=>$name,'status'=>'paused'];
  });
 }
 public function tenants(int $actor): array{return $this->query("SELECT t.id,t.name,m.role,s.status,s.valid_until FROM ai_tenants t JOIN ai_members m ON m.tenant_id=t.id JOIN ai_subscriptions s ON s.tenant_id=t.id WHERE m.user_id=? AND m.status='active' ORDER BY t.id",[$actor])->fetchAll(PDO::FETCH_ASSOC);}
 public function setMember(int $tenant,int $actor,int $user,string $role,bool $active,int $now,callable $userExists): void{
  if(!in_array($role,['admin','operator','viewer'],true)||$actor===$user||!$userExists($user))throw new InvalidArgumentException('Miembro inválido');
  $this->transaction(function()use($tenant,$actor,$user,$role,$active,$now){
   $this->lock($tenant);$this->authorize($tenant,$actor,'members');
   $old=$this->query('SELECT role FROM ai_members WHERE tenant_id=? AND user_id=?',[$tenant,$user])->fetchColumn();if($old==='owner')throw new RuntimeException('No se puede modificar al propietario',403);
   if($old)$this->query('UPDATE ai_members SET role=?,status=? WHERE tenant_id=? AND user_id=?',[$role,$active?'active':'revoked',$tenant,$user]);
   else $this->query('INSERT INTO ai_members(tenant_id,user_id,role,status) VALUES(?,?,?,?)',[$tenant,$user,$role,$active?'active':'revoked']);
   $this->audit($tenant,$actor,'member.updated',(string)$user,['role'=>$role,'status'=>$active?'active':'revoked'],$now);
  });
 }
 public function saveAgent(int $tenant,int $actor,?int $id,string $name,string $module,string $instructions,bool $enabled,int $now): int{
  $name=self::name($name,100);if(!in_array($module,['director','sales','finance','operations','hr','support','marketing','reservations','commerce','logistics'],true)||strlen($instructions)>12000)throw new InvalidArgumentException('Configuración inválida');
  return $this->transaction(function()use($tenant,$actor,$id,$name,$module,$instructions,$enabled,$now){
   $this->lock($tenant);$this->authorize($tenant,$actor,'configure');if($enabled)$this->entitlement($tenant,$now);
   if($id){if(!$this->query('SELECT id FROM ai_agents WHERE id=? AND tenant_id=?',[$id,$tenant])->fetchColumn())throw new RuntimeException('Agente no disponible',404);$this->query('UPDATE ai_agents SET name=?,module=?,instructions=?,enabled=? WHERE id=? AND tenant_id=?',[$name,$module,$instructions,$enabled?1:0,$id,$tenant]);}
   else{$this->query('INSERT INTO ai_agents(tenant_id,name,module,instructions,enabled,created_at) VALUES(?,?,?,?,?,?)',[$tenant,$name,$module,$instructions,$enabled?1:0,$now]);$id=(int)$this->db->lastInsertId();}
   $this->audit($tenant,$actor,'agent.saved',(string)$id,['module'=>$module,'status'=>$enabled?'enabled':'disabled'],$now);return $id;
  });
 }
 public function chargeQuota(int $tenant,string $metric,int $units,int $now): void{
  if(!$this->db->inTransaction()||$units<1)throw new RuntimeException('Reserva inválida');
  $this->lock($tenant);
  $limit=$this->query('SELECT limit_units FROM ai_limits WHERE tenant_id=? AND metric=?',[$tenant,$metric])->fetchColumn();
  if($limit===false)throw new RuntimeException('Cuota no configurada',429);
  $period=gmdate('Y-m',$now);$used=$this->query('SELECT units FROM ai_usage WHERE tenant_id=? AND period=? AND metric=?',[$tenant,$period,$metric])->fetchColumn();
  if($units>(int)$limit-(int)$used)throw new RuntimeException('Cuota agotada',429);
  if($used===false)$this->query('INSERT INTO ai_usage(tenant_id,period,metric,units) VALUES(?,?,?,?)',[$tenant,$period,$metric,$units]);
  else $this->query('UPDATE ai_usage SET units=units+? WHERE tenant_id=? AND period=? AND metric=?',[$units,$tenant,$period,$metric]);
 }
 public function remember(int $tenant,int $actor,string $customer,string $key,array $value,int $version,int $expires,int $now): int{
  $customer=self::name($customer,100);$key=self::name($key,100);$json=self::json($value);if($expires<=$now||$expires>$now+366*86400)throw new InvalidArgumentException('Retención inválida');
  return $this->transaction(function()use($tenant,$actor,$customer,$key,$json,$version,$expires,$now){
   $this->lock($tenant);$this->authorize($tenant,$actor,'execute');$this->entitlement($tenant,$now);
   $old=$this->query('SELECT version FROM ai_memory WHERE tenant_id=? AND customer_key=? AND memory_key=?',[$tenant,$customer,$key])->fetchColumn();
   if((int)$old!==$version)throw new RuntimeException('La memoria cambió',409);$next=$version+1;
   if($old===false)$this->query('INSERT INTO ai_memory(tenant_id,customer_key,memory_key,value_json,version,expires_at,updated_by,updated_at) VALUES(?,?,?,?,?,?,?,?)',[$tenant,$customer,$key,$json,$next,$expires,$actor,$now]);
   else $this->query('UPDATE ai_memory SET value_json=?,version=?,expires_at=?,updated_by=?,updated_at=? WHERE tenant_id=? AND customer_key=? AND memory_key=?',[$json,$next,$expires,$actor,$now,$tenant,$customer,$key]);
   $this->audit($tenant,$actor,'memory.updated',hash('sha256',$customer."\0".$key),['version'=>$next],$now);return $next;
  });
 }
 public function recall(int $tenant,int $actor,string $customer,int $now): array{
  $this->authorize($tenant,$actor);$this->entitlement($tenant,$now);
  return $this->query('SELECT memory_key,value_json,version,expires_at FROM ai_memory WHERE tenant_id=? AND customer_key=? AND expires_at>? ORDER BY memory_key LIMIT 100',[$tenant,$customer,$now])->fetchAll(PDO::FETCH_ASSOC);
 }
}
