<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use PDO;
use RuntimeException;
final class CompanySupportInbox {
 public function __construct(private Core $core){}
 private function binding(int $tenant,int $actor):array|false {
  if($this->core->authorize($tenant,$actor)!=='owner')throw new RuntimeException('owner_required',403);
  return $this->core->query("SELECT b.*,c.bot_active,p.enabled AS pilot_enabled,p.auto_delivery,p.daily_limit,p.used_day,p.used_count FROM support_company_bindings b JOIN agents a ON a.id=b.chat_agent_id AND a.user_id=b.owner_id JOIN chats c ON c.id=b.chat_id AND c.agent_id=a.id JOIN chat_usuarios cu ON cu.chat_id=c.id AND cu.user_id=b.owner_id JOIN ai_agents e ON e.id=b.enterprise_agent_id AND e.tenant_id=b.tenant_id JOIN support_pilot_agents p ON p.agent_id=a.id WHERE b.tenant_id=? AND b.owner_id=?",[$tenant,$actor])->fetch(PDO::FETCH_ASSOC);
 }
 private function rows(array $b,?string $id=null):array {
  $args=[$b['tenant_id'],$b['tenant_id'],$b['chat_agent_id'],$b['owner_id'],$b['chat_id'],$b['owner_id'],$b['start_id']];if($id!==null)$args[]=$id;
  return $this->core->query("SELECT j.id,j.chat_id,j.actor_id,c.bot_active AS case_bot,cl.enabled AS client_enabled,j.source_id,j.source_digest,j.status,j.result_json,j.created_at,j.claim_until,m.contenido,d.message_id AS delivered_id,r.reviewed_at,r.reviewed_by,t.message_id AS human_message_id,t.replied_at,t.resolved_at,hm.contenido AS human_reply FROM support_pilot_jobs j JOIN chats c ON c.id=j.chat_id AND c.agent_id=j.agent_id LEFT JOIN support_company_clients cl ON cl.chat_id=j.chat_id AND cl.agent_id=j.agent_id AND cl.user_id=j.actor_id AND cl.tenant_id=? JOIN mensajes m ON m.id=j.source_id AND m.chat_id=j.chat_id AND m.user_id=j.actor_id LEFT JOIN chat_message_features f ON f.message_id=m.id LEFT JOIN chat_message_users h ON h.message_id=m.id AND h.user_id=j.actor_id LEFT JOIN support_deliveries d ON d.job_id=j.id LEFT JOIN chat_message_features df ON df.message_id=d.message_id LEFT JOIN chat_message_users dh ON dh.message_id=d.message_id AND dh.user_id=j.actor_id LEFT JOIN support_company_reviews r ON r.job_id=j.id LEFT JOIN support_company_tickets t ON t.job_id=j.id AND t.tenant_id=? LEFT JOIN mensajes hm ON hm.id=t.message_id AND hm.chat_id=j.chat_id LEFT JOIN chat_message_features hf ON hf.message_id=hm.id LEFT JOIN chat_message_users hh ON hh.message_id=hm.id AND hh.user_id=j.actor_id WHERE j.agent_id=? AND j.owner_id=? AND ((j.chat_id=? AND j.actor_id=? AND j.source_id>?) OR (cl.chat_id IS NOT NULL AND j.source_id>cl.start_id AND (SELECT COUNT(*) FROM chat_usuarios cm WHERE cm.chat_id=j.chat_id)=2 AND EXISTS(SELECT 1 FROM chat_usuarios cm WHERE cm.chat_id=j.chat_id AND cm.user_id=cl.user_id) AND EXISTS(SELECT 1 FROM chat_usuarios cm WHERE cm.chat_id=j.chat_id AND cm.user_id=cl.bot_id))) AND m.tipo='texto' AND m.file_id IS NULL AND f.attachment_id IS NULL AND f.deleted_at IS NULL AND COALESCE(f.view_once,0)=0 AND h.hidden_at IS NULL AND df.deleted_at IS NULL AND dh.hidden_at IS NULL AND hf.deleted_at IS NULL AND hh.hidden_at IS NULL AND COALESCE(hf.view_once,0)=0 AND (t.message_id IS NULL OR hm.id IS NOT NULL)".($id!==null?' AND j.id=?':'')." ORDER BY j.created_at DESC,j.id DESC LIMIT 50",$args)->fetchAll(PDO::FETCH_ASSOC);
 }
 private function visible(array $r):bool{return hash_equals($r['source_digest'],hash('sha256',$r['contenido']));}
 private function needsReview(array $r,int $now):bool{return ($r['status']==='human_required'||!$r['delivered_id'])&&(in_array($r['status'],['human_required','needs_review','blocked'],true)||($r['status']==='processing'&&(int)$r['claim_until']<=$now));}
 public function read(int $tenant,int $actor,int $now,?string $id=null):array {
  $b=$this->binding($tenant,$actor);if(!$b)return ['connected'=>false,'items'=>[]];
  $items=[];foreach($this->rows($b,$id) as $r){
   if(!$this->visible($r))continue;$result=json_decode((string)$r['result_json'],true);
   $needs=$this->needsReview($r,$now);
   $active=(bool)$b['enabled']&&(bool)$b['pilot_enabled']&&(bool)$r['case_bot']&&((int)$r['chat_id']===(int)$b['chat_id']||(bool)$r['client_enabled']);
   $resolved=$r['resolved_at']!==null;$answered=$r['human_message_id']!==null;
   $canReply=$active&&!$answered&&in_array($r['status'],['human_required','needs_review'],true);
   $items[]=['id'=>$r['id'],'chat_id'=>(int)$r['chat_id'],'user_id'=>(int)$r['actor_id'],'owner_chat'=>(int)$r['chat_id']===(int)$b['chat_id'],'source_id'=>(int)$r['source_id'],'message'=>$r['contenido'],'reply'=>is_string($result['proposal']['reply']??null)?$result['proposal']['reply']:null,'acknowledged'=>(bool)($result['acknowledgement']??false),'pending_response'=>!$answered&&in_array($r['status'],['human_required','needs_review'],true),'can_reply'=>$canReply,'can_resolve'=>$active&&$answered&&!$resolved,'human_reply'=>$r['human_reply'],'replied_at'=>$r['replied_at']?(int)$r['replied_at']:null,'resolved_at'=>$resolved?(int)$r['resolved_at']:null,'status'=>$resolved?'resolved':($answered?'answered':($needs?'review':($r['delivered_id']?'delivered':$r['status']))),'created_at'=>(int)$r['created_at'],'reviewed_at'=>$r['reviewed_at']?(int)$r['reviewed_at']:null,'can_review'=>$needs&&!$r['reviewed_at']];
  }
  return ['connected'=>true,'chat_id'=>(int)$b['chat_id'],'pilot_active'=>(bool)$b['enabled']&&(bool)$b['pilot_enabled']&&(bool)$b['bot_active'],'automatic'=>(bool)$b['auto_delivery'],'daily_limit'=>(int)$b['daily_limit'],'used_today'=>$b['used_day']===gmdate('Y-m-d',$now)?(int)$b['used_count']:0,'items'=>$items];
 }
 public function review(int $tenant,int $actor,string $id,int $now):array {
  if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new \InvalidArgumentException('Caso inválido');
  return $this->core->transaction(function()use($tenant,$actor,$id,$now){
   $this->core->lock($tenant);$b=$this->binding($tenant,$actor);if(!$b)throw new RuntimeException('connection_missing',404);
   $r=$this->rows($b,$id)[0]??null;if(!$r||!$this->visible($r))throw new RuntimeException('case_missing',404);
   if(!$this->needsReview($r,$now))throw new RuntimeException('case_changed',409);
   if(!$r['reviewed_at']){
    $this->core->query('INSERT INTO support_company_reviews(job_id,tenant_id,reviewed_by,reviewed_at) VALUES(?,?,?,?)',[$id,$tenant,$actor,$now]);
    $this->core->audit($tenant,$actor,'support.case_reviewed',$id,['status'=>'reviewed'],$now);
   }
   return ['reviewed'=>true,'sent'=>false,'action_executed'=>false];
  });
 }
}
