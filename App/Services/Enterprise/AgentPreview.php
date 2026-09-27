<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use PDO;
use RuntimeException;
use InvalidArgumentException;
final class AgentPreview {
 public function __construct(private Core $core,private \Closure $generate){}
 private function authorize(int $tenant,int $actor):array {
  if($this->core->authorize($tenant,$actor)!=='owner')throw new RuntimeException('forbidden',403);
  $c=$this->core->query('SELECT owner_id,daily_limit FROM ai_agent_preview_config WHERE tenant_id=?',[$tenant])->fetch(PDO::FETCH_ASSOC);
  if(!$c||(int)$c['owner_id']!==$actor)throw new RuntimeException('preview_not_enabled',403);return $c;
 }
 private function view(array $row):array {
  return ['id'=>$row['id'],'agent_id'=>(int)$row['agent_id'],'status'=>$row['status'],'proposal'=>$row['proposal_json']?json_decode($row['proposal_json'],true):null,'usage'=>$row['usage_json']?json_decode($row['usage_json'],true):null,'created_at'=>(int)$row['created_at'],'sent'=>false];
 }
 public function status(int $tenant,int $actor,int $now):array {
  $config=$this->authorize($tenant,$actor);$start=(int)(floor($now/86400)*86400);
  $used=(int)$this->core->query('SELECT (SELECT COUNT(*) FROM ai_agent_previews WHERE tenant_id=? AND created_at>=?)+(SELECT COUNT(*) FROM ai_app_call_turns WHERE tenant_id=? AND created_at>=?)',[$tenant,$start,$tenant,$start])->fetchColumn();
  $rows=$this->core->query('SELECT * FROM ai_agent_previews WHERE tenant_id=? AND actor_id=? ORDER BY created_at DESC,id DESC LIMIT 10',[$tenant,$actor])->fetchAll(PDO::FETCH_ASSOC);
  return ['profiles'=>AgentPreviewCatalog::all(),'daily_limit'=>(int)$config['daily_limit'],'used_today'=>$used,'items'=>array_map(fn($r)=>$this->view($r),$rows),'sent'=>false];
 }
 public function run(int $tenant,int $actor,int $agent,string $id,string $message,int $now,?string $requiredModule=null):array {
  if(!preg_match('/^[a-f0-9]{32}$/D',$id)||trim($message)===''||strlen($message)>3000)throw new InvalidArgumentException('invalid_input');
  $prepared=$this->core->transaction(function()use($tenant,$actor,$agent,$id,$message,$now,$requiredModule){
   $this->core->lock($tenant);$config=$this->authorize($tenant,$actor);
   $a=$this->core->query("SELECT a.name,a.instructions,a.module,t.name AS company FROM ai_agents a JOIN ai_tenants t ON t.id=a.tenant_id WHERE a.id=? AND a.tenant_id=?",[$agent,$tenant])->fetch(PDO::FETCH_ASSOC);
   if(!$a)throw new RuntimeException('agent_required',404);
   if($requiredModule!==null&&$a['module']!==$requiredModule)throw new RuntimeException('agent_changed',409);
   AgentPreviewCatalog::profile($a['module']);
   if(strlen($a['instructions'])>3000)throw new InvalidArgumentException('instructions_too_long');
   $profile=(new CompanyProfile($this->core))->read($tenant,$actor);
   $hash=hash('sha256',Core::json([$tenant,$actor,$agent,$message,$a,$profile],18000));
   $old=$this->core->query('SELECT * FROM ai_agent_previews WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
   if($old){if((int)$old['tenant_id']!==$tenant||(int)$old['actor_id']!==$actor||$old['request_hash']!==$hash)throw new RuntimeException('request_changed',409);return ['existing'=>$this->view($old)];}
   $start=(int)(floor($now/86400)*86400);
   if((int)$this->core->query('SELECT (SELECT COUNT(*) FROM ai_agent_previews WHERE tenant_id=? AND created_at>=?)+(SELECT COUNT(*) FROM ai_app_call_turns WHERE tenant_id=? AND created_at>=?)',[$tenant,$start,$tenant,$start])->fetchColumn()>=(int)$config['daily_limit'])throw new RuntimeException('daily_limit',429);
   $this->core->query("INSERT INTO ai_agent_previews(id,tenant_id,actor_id,agent_id,request_hash,status,created_at) VALUES(?,?,?,?,?,'running',?)",[$id,$tenant,$actor,$agent,$hash,$now]);
   $this->core->audit($tenant,$actor,'agent.preview_started',$id,['status'=>'running'],$now);
   return ['agent'=>$a,'profile'=>$profile];
  });
  if(isset($prepared['existing']))return $prepared['existing'];
  $knowledge=[['id'=>'preview-scope','text'=>'Prueba privada de asistencia para el propietario de la empresa '.$prepared['agent']['company'].'. No dispone de herramientas para consultar registros, modificar datos, enviar mensajes ni contactar operadores.'],['id'=>'agent-configuration','text'=>$prepared['agent']['instructions']]];
  if(array_filter($prepared['profile']['fields'],fn($v)=>$v!==''))$knowledge[]=['id'=>'company-profile','text'=>Core::json($prepared['profile']['fields'],4000)];
  $knowledge[]=['id'=>'agent-role','text'=>Core::json(AgentPreviewCatalog::profile($prepared['agent']['module']),4000)];
  $proposal=null;$usage=null;$status='failed';
  try{
   $result=($this->generate)(['id'=>$id,'tenant_id'=>$tenant,'actor_id'=>$actor,'agent_id'=>$agent,'preview_module'=>$prepared['agent']['module']],$message,$knowledge,[],'general');
   $proposal=SupportContract::proposal(json_encode($result['proposal']??null,JSON_THROW_ON_ERROR),array_column($knowledge,'id'));
   $usage=$result['usage']??null;
   foreach(['prompt_tokens','completion_tokens','total_tokens']as$key)if(!is_int($usage[$key]??null)||$usage[$key]<0)throw new RuntimeException('invalid_usage');
   if($usage['total_tokens']!==$usage['prompt_tokens']+$usage['completion_tokens'])throw new RuntimeException('invalid_usage');
   $usage=array_intersect_key($usage,array_flip(['prompt_tokens','completion_tokens','total_tokens']));
   $status=$proposal['decision']==='escalate'?'needs_review':'draft';
  }catch(\Throwable $e){$proposal=null;$usage=null;}
  $this->core->transaction(function()use($tenant,$actor,$id,$status,$proposal,$usage,$now){
   $this->core->lock($tenant);$this->authorize($tenant,$actor);
   $this->core->query("UPDATE ai_agent_previews SET status=?,proposal_json=?,usage_json=? WHERE id=? AND tenant_id=? AND status='running'",[$status,$proposal?json_encode($proposal,JSON_THROW_ON_ERROR):null,$usage?json_encode($usage,JSON_THROW_ON_ERROR):null,$id,$tenant]);
   $this->core->audit($tenant,$actor,'agent.preview_completed',$id,['status'=>$status],$now);
  });
  return $this->view($this->core->query('SELECT * FROM ai_agent_previews WHERE id=? AND tenant_id=?',[$id,$tenant])->fetch(PDO::FETCH_ASSOC));
 }
}
