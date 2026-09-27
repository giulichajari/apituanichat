<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use PDO;
use RuntimeException;
use InvalidArgumentException;
final class RecordProposals {
 public function __construct(private Core $core,private RecordJobs $jobs,private BusinessRecords $records){}
 public static function kinds(string $module):array{return array_keys(array_filter(RecordJobs::MODULES,fn($modules)=>in_array($module,$modules,true)));}
 private function agent(int $tenant,int $actor,int $agent,int $now):array{
  $c=$this->core;$c->authorize($tenant,$actor,'execute');$c->entitlement($tenant,$now);
  $a=$c->query('SELECT id,module,instructions,enabled FROM ai_agents WHERE tenant_id=? AND id=?',[$tenant,$agent])->fetch(PDO::FETCH_ASSOC);
  if(!$a||(int)$a['enabled']!==1||!self::kinds($a['module']))throw new RuntimeException('Agente no disponible para gestión',403);
  if(in_array($a['module'],['finance','hr'],true))$c->authorize($tenant,$actor,'configure');
  if(strlen($a['instructions'])>5000)throw new InvalidArgumentException('Reduce las instrucciones del agente para preparar propuestas');
  return $a;
 }
 private function row(int $tenant,int $actor,string $id):array{
  $this->core->authorize($tenant,$actor,'execute');
  $r=$this->core->query('SELECT * FROM ai_record_proposals WHERE tenant_id=? AND actor_id=? AND id=?',[$tenant,$actor,$id])->fetch(PDO::FETCH_ASSOC);
  if(!$r)throw new RuntimeException('Propuesta no disponible',404);
  if(in_array($r['agent_module'],['finance','hr'],true))$this->core->authorize($tenant,$actor,'configure');return $r;
 }
 private function view(array $r,int $now):array{
  $status=$r['status'];if($status==='running'&&(int)$r['created_at']+120<$now)$status='interrupted';
  return ['id'=>$r['id'],'agent_id'=>(int)$r['agent_id'],'brief'=>$r['brief'],'status'=>$status,'message'=>$r['message'],'record'=>$r['record_json']?json_decode($r['record_json'],true):null,'digest'=>$r['digest'],'event_id'=>$r['event_id'],'expires_at'=>(int)$r['expires_at'],'expired'=>(int)$r['expires_at']<=$now,'created_at'=>(int)$r['created_at']];
 }
 public function listing(int $tenant,int $actor,int $now):array{
  $role=$this->core->authorize($tenant,$actor,'execute');
  $filter=in_array($role,['owner','admin'],true)?'':" AND agent_module NOT IN ('finance','hr')";
  $rows=$this->core->query('SELECT * FROM ai_record_proposals WHERE tenant_id=? AND actor_id=?'.$filter.' ORDER BY created_at DESC,id DESC LIMIT 30',[$tenant,$actor])->fetchAll(PDO::FETCH_ASSOC);
  return ['proposals'=>array_map(fn($r)=>$this->view($r,$now),$rows)];
 }
 public function validate(mixed $p,string $module):array{
  if(!is_array($p)||array_is_list($p))throw new InvalidArgumentException('Formato inválido');
  $keys=array_keys($p);sort($keys);
  if($keys!==['decision','message','record']||!in_array($p['decision'],['propose','clarify'],true)||!is_string($p['message'])||trim($p['message'])===''||strlen($p['message'])>2000)throw new InvalidArgumentException('Propuesta inválida');
  if($p['decision']==='clarify'){if($p['record']!==null)throw new InvalidArgumentException('Aclaración con acción');return $p;}
  $r=$p['record'];if(!is_array($r)||array_is_list($r))throw new InvalidArgumentException('Registro inválido');$keys=array_keys($r);sort($keys);
  if($keys!==['fields','kind','title']||!is_string($r['kind'])||!in_array($r['kind'],self::kinds($module),true)||!is_string($r['title'])||!is_array($r['fields']))throw new InvalidArgumentException('Herramienta no admitida');
  $status=BusinessRecords::KINDS[$r['kind']][0];
  $this->records->validateRecord($r['kind'],$r['title'],$status,$r['fields']);
  return $p;
 }
 public function generate(int $tenant,int $actor,int $agent,string $id,string $brief,callable $generate,callable $clock):array{
  if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException('Identificador inválido');$brief=trim($brief);
  if($brief===''||strlen($brief)>3000||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$brief))throw new InvalidArgumentException('Revisa el encargo; máximo 3000 bytes');
  $prepared=$this->core->transaction(function()use($tenant,$actor,$agent,$id,$brief,$clock){
   $c=$this->core;$c->lock($tenant);$now=$clock();$a=$this->agent($tenant,$actor,$agent,$now);
   $old=$c->query('SELECT * FROM ai_record_proposals WHERE tenant_id=? AND id=?',[$tenant,$id])->fetch(PDO::FETCH_ASSOC);
   if($old){if((int)$old['actor_id']!==$actor||(int)$old['agent_id']!==$agent||$old['brief']!==$brief)throw new RuntimeException('Solicitud modificada',409);if(in_array($old['agent_module'],['finance','hr'],true))$c->authorize($tenant,$actor,'configure');return ['existing'=>$this->view($old,$now)];}
   $c->chargeQuota($tenant,'executions',1,$now);
   $hash=hash('sha256',Core::json($a));
   $c->query("INSERT INTO ai_record_proposals(tenant_id,id,actor_id,agent_id,agent_module,agent_hash,brief,status,message,created_at,expires_at) VALUES(?,?,?,?,?,?,?,'running','Preparando propuesta',?,?)",[$tenant,$id,$actor,$agent,$a['module'],$hash,$brief,$now,$now+86400]);
   $c->audit($tenant,$actor,'record.proposal_started',$id,['module'=>$a['module']],$now);return ['agent'=>$a,'hash'=>$hash,'context'=>(new AgentContext($c))->read($tenant,$actor,$now)];
  });
  if(isset($prepared['existing']))return $prepared['existing'];
  $p=null;$usage=null;$status='failed';$message='No se pudo generar una propuesta válida. No se ejecutó ninguna acción.';
  try{
   $a=$prepared['agent'];$response=$generate($brief,$a['module'],$a['instructions'],self::kinds($a['module']),$prepared['context']);
   $p=$this->validate($response['proposal']??null,$a['module']);$usage=$response['usage']??null;
   foreach(['prompt_tokens','completion_tokens','total_tokens'] as $key)if(!is_int($usage[$key]??null)||$usage[$key]<0)throw new RuntimeException('Uso inválido');
   if($usage['total_tokens']!==$usage['prompt_tokens']+$usage['completion_tokens'])throw new RuntimeException('Uso inválido');
   $status=$p['decision']==='propose'?'draft':'clarify';$message=$p['message'];
  }catch(\Throwable $e){$p=null;$usage=null;}
  $this->core->transaction(function()use($tenant,$actor,$agent,$id,$prepared,$p,$usage,$status,$message,$clock){
   $c=$this->core;$c->lock($tenant);$now=$clock();
   $row=$this->row($tenant,$actor,$id);if($row['status']!=='running')throw new RuntimeException('Estado modificado',409);
   $a=$this->agent($tenant,$actor,$agent,$now);if(!hash_equals($prepared['hash'],hash('sha256',Core::json($a))))throw new RuntimeException('El agente cambió; crea otra propuesta',409);
   $record=null;if($status==='draft'){$r=$p['record'];$record=['id'=>bin2hex(random_bytes(16)),'kind'=>$r['kind'],'title'=>$r['title'],'status'=>BusinessRecords::KINDS[$r['kind']][0],'fields'=>$r['fields'],'version'=>0];}
   $json=$record?Core::json($record):null;$digest=$json?hash('sha256',$json):null;
   $c->query('UPDATE ai_record_proposals SET status=?,message=?,record_json=?,digest=?,usage_json=? WHERE tenant_id=? AND id=?',[$status,$message,$json,$digest,$usage?Core::json($usage):null,$tenant,$id]);
   $c->audit($tenant,$actor,'record.proposal_ready',$id,['status'=>$status,'digest'=>hash('sha256',Core::json($prepared['context']))],$now);
  });
  return $this->view($this->row($tenant,$actor,$id),$clock());
 }
 public function accept(int $tenant,int $actor,string $id,string $digest,int $now,?string $parent=null):array{
  return $this->core->transaction(function()use($tenant,$actor,$id,$digest,$now,$parent){
   $c=$this->core;$c->lock($tenant);$r=$this->row($tenant,$actor,$id);
   if(!is_string($r['digest'])||!hash_equals($r['digest'],$digest))throw new RuntimeException('Revisa la propuesta actual',409);
   if($r['status']==='queued'){
    $event=$c->query('SELECT parent_id FROM ai_events WHERE tenant_id=? AND id=?',[$tenant,$r['event_id']])->fetch(PDO::FETCH_ASSOC);
    if(!$event||$event['parent_id']!==$parent)throw new RuntimeException('La dependencia enviada era distinta; actualiza las propuestas',409);
    return $this->view($r,$now);
   }
   if($r['status']!=='draft'||(int)$r['expires_at']<=$now)throw new RuntimeException('La propuesta ya no está disponible',409);
   $a=$this->agent($tenant,$actor,(int)$r['agent_id'],$now);if(!hash_equals($r['agent_hash'],hash('sha256',Core::json($a))))throw new RuntimeException('El agente cambió; genera otra propuesta',409);
   $record=json_decode($r['record_json'],true,16,JSON_THROW_ON_ERROR);
   $event=$this->jobs->enqueue($tenant,$actor,(int)$r['agent_id'],$record,'proposal_'.$id,$now,$parent);
   $c->query("UPDATE ai_record_proposals SET status='queued',event_id=? WHERE tenant_id=? AND id=?",[$event['id'],$tenant,$id]);
   $c->audit($tenant,$actor,'record.proposal_accepted',$id,['event_id'=>$event['id'],'digest'=>$digest],$now);
   return $this->view($this->row($tenant,$actor,$id),$now);
  });
 }
}
