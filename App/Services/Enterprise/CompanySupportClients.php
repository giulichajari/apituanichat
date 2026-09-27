<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use PDO;
use RuntimeException;
final class CompanySupportClients {
 private Core $core;
 public function __construct(private PDO $db,private int $bot){$this->core=new Core($db);}
 private function company(int $tenant):array {
  $b=$this->core->query("SELECT b.*,t.name FROM support_company_bindings b JOIN ai_tenants t ON t.id=b.tenant_id JOIN agents a ON a.id=b.chat_agent_id AND a.user_id=b.owner_id JOIN support_pilot_agents p ON p.agent_id=a.id JOIN ai_members m ON m.tenant_id=b.tenant_id AND m.user_id=b.owner_id AND m.role='owner' AND m.status='active' WHERE b.tenant_id=? AND b.enabled=1 AND p.enabled=1",[$tenant])->fetch(PDO::FETCH_ASSOC);
  if(!$b)throw new RuntimeException('unavailable',404);return $b;
 }
 public function info(int $tenant,int $actor):array {
  if($actor<1)throw new RuntimeException('login',401);$b=$this->company($tenant);
  return ['company'=>$b['name'],'customer_daily_limit'=>5];
 }
 public function join(int $tenant,int $actor,int $now):array {
  $b=$this->company($tenant);
  return $this->core->transaction(function()use($tenant,$actor,$now,$b){
   // Serialize enrollment with the pilot. Never add the company owner to the client's chat.
   $this->core->query('SELECT id FROM agents WHERE id=?'.$this->core->lockSuffix(),[$b['chat_agent_id']])->fetchColumn();
   if($actor<1||$this->bot<1||$actor===$this->bot||!$this->core->query('SELECT id FROM users WHERE id=?'.$this->core->lockSuffix(),[$actor])->fetchColumn())throw new RuntimeException('user_unavailable',403);
   $bot=$this->core->query('SELECT id,name,avatar FROM users WHERE id=?',[$this->bot])->fetch(PDO::FETCH_ASSOC);if(!$bot)throw new RuntimeException('bot_unavailable',503);
   $b=$this->company($tenant);
   if($actor===(int)$b['owner_id'])return ['contact'=>['id'=>$this->bot,'name'=>$bot['name'],'avatar'=>$bot['avatar'],'chat_id'=>(int)$b['chat_id']]];
   $old=$this->core->query('SELECT * FROM support_company_clients WHERE tenant_id=? AND user_id=?',[$tenant,$actor])->fetch(PDO::FETCH_ASSOC);
   if($old){
    if(!(int)$old['enabled'])throw new RuntimeException('connection_disabled',403);
    $chat=(int)$old['chat_id'];
   }else{
    $chats=$this->core->query('SELECT c.id,c.agent_id FROM chats c JOIN chat_usuarios a ON a.chat_id=c.id AND a.user_id=? JOIN chat_usuarios b ON b.chat_id=c.id AND b.user_id=? WHERE (SELECT COUNT(*) FROM chat_usuarios x WHERE x.chat_id=c.id)=2 ORDER BY c.id',[$actor,$this->bot])->fetchAll(PDO::FETCH_ASSOC);
    if(count($chats)>1)throw new RuntimeException('ambiguous_chat',409);
    if($chats){$chat=(int)$chats[0]['id'];if($chats[0]['agent_id'])throw new RuntimeException('chat_already_connected',409);}
    else{
     $this->core->query('INSERT INTO chats(name,created_at,last_message_at,bot_active) VALUES(NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,0)');$chat=(int)$this->db->lastInsertId();
     foreach([$actor,$this->bot] as $user)$this->core->query('INSERT INTO chat_usuarios(chat_id,user_id,added_at,leido) VALUES(?,?,CURRENT_TIMESTAMP,0)',[$chat,$user]);
    }
    $c=$this->core->query('SELECT agent_id FROM chats WHERE id=?'.$this->core->lockSuffix(),[$chat])->fetch(PDO::FETCH_ASSOC);
    if(!$c||$c['agent_id']||$this->core->query('SELECT chat_id FROM support_company_clients WHERE chat_id=?',[$chat])->fetchColumn())throw new RuntimeException('chat_changed',409);
    $cutoff=(int)$this->core->query('SELECT COALESCE(MAX(id),0) FROM mensajes WHERE chat_id=?',[$chat])->fetchColumn();
    $this->core->query('INSERT INTO support_company_clients(chat_id,tenant_id,user_id,bot_id,agent_id,start_id,enabled,created_at) VALUES(?,?,?,?,?,?,1,?)',[$chat,$tenant,$actor,$this->bot,$b['chat_agent_id'],$cutoff,$now]);
    $this->core->query('UPDATE chats SET agent_id=?,bot_active=1 WHERE id=?',[$b['chat_agent_id'],$chat]);
   }
   $this->core->lock($tenant);
   $c=$this->core->query('SELECT agent_id,bot_active FROM chats WHERE id=?',[$chat])->fetch(PDO::FETCH_ASSOC);
   $members=$this->core->query('SELECT user_id FROM chat_usuarios WHERE chat_id=? ORDER BY user_id',[$chat])->fetchAll(PDO::FETCH_COLUMN);$want=[$actor,$this->bot];sort($want);
   if(!$c||(int)$c['agent_id']!==(int)$b['chat_agent_id']||!(int)$c['bot_active']||array_map('intval',$members)!==$want)throw new RuntimeException('chat_changed',409);
   if(!$old)$this->core->audit($tenant,$actor,'support.client_joined',(string)$chat,['status'=>'active'],$now);
   return ['contact'=>['id'=>$this->bot,'name'=>$bot['name'],'avatar'=>$bot['avatar'],'chat_id'=>$chat]];
  });
 }
}
