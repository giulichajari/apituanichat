<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use PDO;
use Closure;
use RuntimeException;
use InvalidArgumentException;

/** Human reply and closure in the existing owner-only pilot. No provider calls. */
final class CompanySupportTickets {
 private Core $core;
 public function __construct(private PDO $db,private int $bot,private Closure $queuePush){$this->core=new Core($db);}
 private function lockedCase(int $tenant,int $actor,string $id,int $now):array {
  if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException('Caso inválido');
  if($this->core->authorize($tenant,$actor)!=='owner')throw new RuntimeException('owner_required',403);
  $b=$this->core->query('SELECT * FROM support_company_bindings WHERE tenant_id=? AND owner_id=?',[$tenant,$actor])->fetch(PDO::FETCH_ASSOC);
  if(!$b)throw new RuntimeException('connection_missing',404);
  // Same lock order as automatic delivery: agent, chat, tenant, job.
  $this->core->query('SELECT id FROM agents WHERE id=?'.$this->core->lockSuffix(),[$b['chat_agent_id']])->fetchColumn();
  $job=$this->core->query('SELECT chat_id FROM support_pilot_jobs WHERE id=? AND agent_id=? AND owner_id=?',[$id,$b['chat_agent_id'],$actor])->fetch(PDO::FETCH_ASSOC);
  if(!$job)throw new RuntimeException('case_missing',404);
  $this->core->query('SELECT id FROM chats WHERE id=?'.$this->core->lockSuffix(),[$job['chat_id']])->fetchColumn();
  $this->core->lock($tenant);
  $this->core->query('SELECT id FROM support_pilot_jobs WHERE id=?'.$this->core->lockSuffix(),[$id])->fetchColumn();
  $view=(new CompanySupportInbox($this->core))->read($tenant,$actor,$now,$id);
  if(!$view['connected']||($view['chat_id']??null)!==(int)$b['chat_id'])throw new RuntimeException('connection_changed',409);
  $item=$view['items'][0]??null;
  if(!$item||$item['id']!==$id)throw new RuntimeException('case_missing',404);
  return [$view,$item];
 }
 public function reply(int $tenant,int $actor,string $id,string $text,int $now):array {
  if(trim($text)===''||strlen($text)>4000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$text))throw new InvalidArgumentException('Respuesta inválida');
  $text=trim($text);
  $digest=hash('sha256',$text);
  return $this->core->transaction(function()use($tenant,$actor,$id,$text,$now,$digest){
   [$view,$item]=$this->lockedCase($tenant,$actor,$id,$now);
   $existing=$this->core->query('SELECT * FROM support_company_tickets WHERE job_id=? AND tenant_id=?',[$id,$tenant])->fetch(PDO::FETCH_ASSOC);
   if($existing){
    if(!hash_equals($existing['reply_digest'],$digest))throw new RuntimeException('reply_already_sent',409);
    return ['sent'=>true,'message_id'=>(int)$existing['message_id'],'repeat'=>true];
   }
   if(!$view['pilot_active']||!$item['can_reply'])throw new RuntimeException('case_changed',409);
   if($this->bot<1||$this->bot===$actor||!$this->core->query('SELECT id FROM users WHERE id=?',[$this->bot])->fetchColumn())throw new RuntimeException('bot_unavailable',503);
   $chat=$item['chat_id'];$body='Respuesta del equipo · Ticket #'.$item['source_id']."\n".$text;
   $this->core->query('INSERT INTO mensajes(chat_id,user_id,contenido,tipo,file_id) VALUES(?,?,?,?,NULL)',[$chat,$this->bot,$body,'texto']);
   $message=(int)$this->db->lastInsertId();
   $this->core->query('INSERT INTO support_company_tickets(job_id,tenant_id,replied_by,message_id,reply_digest,replied_at) VALUES(?,?,?,?,?,?)',[$id,$tenant,$actor,$message,$digest,$now]);
   $this->core->query('UPDATE chats SET last_message_at=CURRENT_TIMESTAMP WHERE id=?',[$chat]);
   $this->core->query('INSERT INTO support_delivery_notices(message_id,chat_id,created_at) VALUES(?,?,?)',[$message,$chat,$now]);
   ($this->queuePush)($this->db,$chat,$this->bot,$message,$body,$now);
   // Prevent a late automatic acknowledgement after the human response.
   $this->core->query("UPDATE support_pilot_jobs SET status='human_answered',updated_at=? WHERE id=?",[$now,$id]);
   $this->core->audit($tenant,$actor,'support.ticket_replied',$id,['status'=>'answered'],$now);
   return ['sent'=>true,'message_id'=>$message,'repeat'=>false];
  });
 }
 public function resolve(int $tenant,int $actor,string $id,int $now):array {
  return $this->core->transaction(function()use($tenant,$actor,$id,$now){
   [$view,$item]=$this->lockedCase($tenant,$actor,$id,$now);
   $ticket=$this->core->query('SELECT * FROM support_company_tickets WHERE job_id=? AND tenant_id=?',[$id,$tenant])->fetch(PDO::FETCH_ASSOC);
   if(!$ticket)throw new RuntimeException('reply_required',409);
   if($ticket['resolved_at']!==null)return ['resolved'=>true,'repeat'=>true,'sent'=>false];
   if(!$view['pilot_active']||!$item['can_resolve'])throw new RuntimeException('case_changed',409);
   $this->core->query('UPDATE support_company_tickets SET resolved_at=?,resolved_by=? WHERE job_id=?',[$now,$actor,$id]);
   $this->core->query("UPDATE support_pilot_jobs SET status='human_resolved',updated_at=? WHERE id=?",[$now,$id]);
   $this->core->audit($tenant,$actor,'support.ticket_resolved',$id,['status'=>'resolved'],$now);
   return ['resolved'=>true,'repeat'=>false,'sent'=>false];
  });
 }
}
