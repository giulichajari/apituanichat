<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use PDO;
use RuntimeException;
use InvalidArgumentException;
/** Persistent work and human revisions. Generation shares the existing preview quota. */
final class AgentTasks {
 public function __construct(private Core $core,private AgentPreview $preview){}
 private function authorize(int $tenant,int $actor,int $now):void {$this->preview->status($tenant,$actor,$now);}
 private function task(int $tenant,string $id):array {
  $t=$this->core->query('SELECT * FROM ai_agent_tasks WHERE tenant_id=? AND id=?',[$tenant,$id])->fetch(PDO::FETCH_ASSOC);
  if(!$t)throw new RuntimeException('task_not_found',404);return $t;
 }
 private function view(array $t):array {
  $p=$this->core->query('SELECT status,proposal_json FROM ai_agent_previews WHERE id=? AND tenant_id=? AND agent_id=?',[$t['id'],$t['tenant_id'],$t['agent_id']])->fetch(PDO::FETCH_ASSOC);
  $proposal=$p&&$p['proposal_json']?json_decode($p['proposal_json'],true):null;
  return ['id'=>$t['id'],'agent_id'=>(int)$t['agent_id'],'agent_name'=>$t['agent_name'],'module'=>$t['module'],'title'=>$t['title'],'brief'=>$t['brief'],
   'status'=>(int)$t['reviewed_at']>0?'reviewed':($p['status']??'pending'),'generated_text'=>$proposal['reply']??null,'draft'=>$t['draft_text']??($proposal['reply']??''),
   'version'=>(int)$t['version'],'created_at'=>(int)$t['created_at'],'reviewed_at'=>(int)$t['reviewed_at'],'published'=>false];
 }
 public function listing(int $tenant,int $actor,int $now):array {
  $quota=$this->preview->status($tenant,$actor,$now);
  $rows=$this->core->query('SELECT * FROM ai_agent_tasks WHERE tenant_id=? ORDER BY created_at DESC,id DESC LIMIT 50',[$tenant])->fetchAll(PDO::FETCH_ASSOC);
  return ['profiles'=>AgentPreviewCatalog::all(),'items'=>array_map(fn($t)=>$this->view($t),$rows),'used_today'=>$quota['used_today'],'daily_limit'=>$quota['daily_limit']];
 }
 public function create(int $tenant,int $actor,int $agent,string $id,string $title,string $brief,int $now):array {
  if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException('invalid_id');
  $title=Core::name($title,120);$brief=trim($brief);if($brief===''||strlen($brief)>2400)throw new InvalidArgumentException('invalid_brief');
  return $this->core->transaction(function()use($tenant,$actor,$agent,$id,$title,$brief,$now){
   $c=$this->core;$c->lock($tenant);$this->authorize($tenant,$actor,$now);
   $old=$c->query('SELECT * FROM ai_agent_tasks WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
   if($old){if((int)$old['tenant_id']!==$tenant||(int)$old['agent_id']!==$agent||$old['title']!==$title||$old['brief']!==$brief)throw new RuntimeException('task_changed',409);return $this->view($old);}
   if($c->query('SELECT id FROM ai_agent_previews WHERE id=?',[$id])->fetchColumn())throw new RuntimeException('id_used',409);
   $a=$c->query("SELECT name,module FROM ai_agents WHERE tenant_id=? AND id=?",[$tenant,$agent])->fetch(PDO::FETCH_ASSOC);
   if(!$a)throw new RuntimeException('agent_not_available',404);
   AgentPreviewCatalog::profile($a['module']);
   $c->query('INSERT INTO ai_agent_tasks(id,tenant_id,agent_id,agent_name,module,title,brief,version,created_at,reviewed_at) VALUES(?,?,?,?,?,?,?,1,?,0)',[$id,$tenant,$agent,$a['name'],$a['module'],$title,$brief,$now]);
   $c->audit($tenant,$actor,'agent.task_created',$id,['module'=>$a['module']],$now);return $this->view($this->task($tenant,$id));
  });
 }
 public function generate(int $tenant,int $actor,string $id,int $now):array {
  $this->authorize($tenant,$actor,$now);$t=$this->task($tenant,$id);
  if($this->core->query('SELECT id FROM ai_agent_previews WHERE id=? AND tenant_id=?',[$id,$tenant])->fetchColumn())return $this->view($t);
  $module=$this->core->query('SELECT module FROM ai_agents WHERE id=? AND tenant_id=?',[$t['agent_id'],$tenant])->fetchColumn();
  if($module!==$t['module'])throw new RuntimeException('agent_changed',409);
  $this->preview->run($tenant,$actor,(int)$t['agent_id'],$id,'Encargo: '.$t['title']."\n".$t['brief'],$now,$t['module']);
  $this->authorize($tenant,$actor,$now);return $this->view($this->task($tenant,$id));
 }
 public function save(int $tenant,int $actor,string $id,string $text,int $version,bool $review,int $now):array {
  $text=trim($text);if($text===''||strlen($text)>8000||$version<1)throw new InvalidArgumentException('invalid_draft');
  return $this->core->transaction(function()use($tenant,$actor,$id,$text,$version,$review,$now){
   $c=$this->core;$c->lock($tenant);$this->authorize($tenant,$actor,$now);$t=$this->task($tenant,$id);
   if((int)$t['version']!==$version)throw new RuntimeException('version_changed',409);
   $v=$this->view($t);if(!in_array($v['status'],['draft','needs_review','reviewed'],true))throw new RuntimeException('draft_not_ready',409);
   $c->query('UPDATE ai_agent_tasks SET draft_text=?,version=version+1,reviewed_at=? WHERE id=? AND tenant_id=?',[$text,$review?$now:0,$id,$tenant]);
   $c->audit($tenant,$actor,$review?'agent.task_reviewed':'agent.task_draft_saved',$id,['version'=>$version+1],$now);
   return $this->view($this->task($tenant,$id));
  });
 }
}
