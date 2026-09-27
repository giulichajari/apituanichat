<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
/** Explicit shared company context, never an unrestricted customer-history dump. */
final class AgentContext {
 public function __construct(private Core $core){}
 public function read(int $tenant,int $actor,int $now):array{
  $this->core->authorize($tenant,$actor,'execute');$this->core->entitlement($tenant,$now);
  $r=$this->core->query("SELECT value_json,version FROM ai_memory WHERE tenant_id=? AND customer_key='empresa' AND memory_key='contexto-agentes' AND expires_at>?",[$tenant,$now])->fetch(\PDO::FETCH_ASSOC);
  if(!$r)return [];
  $v=json_decode($r['value_json'],true);$note=$v['note']??null;
  if(!is_string($note)||trim($note)===''||strlen($note)>2000)return [];
  return ['nota_compartida_no_autoritativa'=>$note,'version'=>(int)$r['version']];
 }
 public function result(int $tenant,int $actor,string $event,string $record,string $kind,string $module,int $now):void{
  // No HR, financial or customer contents are copied into shared memory.
  $this->core->remember($tenant,$actor,'ejecuciones',$event,['event_id'=>$event,'record_id'=>$record,'kind'=>$kind,'module'=>$module,'outcome'=>'record_saved'],0,$now+90*86400,$now);
 }
}
