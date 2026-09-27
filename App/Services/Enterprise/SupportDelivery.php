<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use PDO;
use Closure;
use RuntimeException;

/** One persisted message per proposal. External notification follows commit. */
final class SupportDelivery
{
    public function __construct(private PDO $db,private int $bot,private Closure $queuePush){}
    private function q(string $sql,array $args=[]):\PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
    private function lock():string{return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';}
    public function automatic(int $agent,string $job,string $secret,int $now):array{
        if(!preg_match('/^[a-f0-9]{32}$/D',$job)||$agent<1)throw new RuntimeException('invalid_job',400);
        $this->db->beginTransaction();
        try{
            $a=$this->q('SELECT a.user_id,a.secret,p.enabled,p.auto_delivery,p.auto_after FROM agents a JOIN support_pilot_agents p ON p.agent_id=a.id WHERE a.id=?'.$this->lock(),[$agent])->fetch(PDO::FETCH_ASSOC);
            if(!$a||strlen((string)$a['secret'])<32||!hash_equals($a['secret'],$secret))throw new RuntimeException('unauthorized',401);
            $j=$this->q('SELECT * FROM support_pilot_jobs WHERE id=? AND agent_id=?',[$job,$agent])->fetch(PDO::FETCH_ASSOC);
            if(!$j||(int)$j['owner_id']!==(int)$a['user_id'])throw new RuntimeException('job_unavailable',403);
            $chat=(int)$j['chat_id'];
            $c=$this->q('SELECT agent_id,bot_active FROM chats WHERE id=?'.$this->lock(),[$chat])->fetch(PDO::FETCH_ASSOC);
            if(!$c||(int)$c['agent_id']!==$agent)throw new RuntimeException('chat_unavailable',403);
            $company=(new SupportCompanyBinding($this->db))->resolve($agent,$chat,(int)$a['user_id'],(int)$j['actor_id'],(int)$j['source_id']);
            if(($company['client']??false)&&$company['bot_id']!==$this->bot)throw new RuntimeException('bot_changed',403);
            $members=($company['client']??false)?[$this->bot,(int)$j['actor_id']]:[(int)$a['user_id'],(int)$j['actor_id']];
            foreach($members as $user)
                if(!$this->q('SELECT 1 FROM chat_usuarios WHERE chat_id=? AND user_id=?',[$chat,$user])->fetchColumn())throw new RuntimeException('membership_required',403);
            $existing=$this->q('SELECT message_id FROM support_deliveries WHERE job_id=?',[$job])->fetchColumn();
            if($existing){$this->db->commit();return ['status'=>'saved_to_chat','message_id'=>(int)$existing,'repeat'=>true];}
            if(!(int)$a['enabled']||!(int)$a['auto_delivery']||!(int)$c['bot_active']){$this->db->commit();return ['status'=>'review_only'];}
            if(!in_array($j['status'],['draft','human_required'],true)){$this->db->commit();return ['status'=>'review_only'];}
            if((int)$j['created_at']<=(int)$a['auto_after']||(int)$j['created_at']<$now-600){$this->db->commit();return ['status'=>'review_only'];}
            $source=$this->q('SELECT m.user_id,m.contenido,f.deleted_at,f.view_once FROM mensajes m LEFT JOIN chat_message_features f ON f.message_id=m.id WHERE m.id=? AND m.chat_id=?',[$j['source_id'],$chat])->fetch(PDO::FETCH_ASSOC);
            if(!$source||(int)$source['user_id']!==(int)$j['actor_id']||$source['deleted_at']||$source['view_once']||!hash_equals($j['source_digest'],hash('sha256',$source['contenido'])))throw new RuntimeException('source_changed',409);
            foreach($members as $user)
                if($this->q('SELECT hidden_at FROM chat_message_users WHERE message_id=? AND user_id=?',[$j['source_id'],$user])->fetchColumn())throw new RuntimeException('source_unavailable',409);
            $result=json_decode((string)$j['result_json'],true,32,JSON_THROW_ON_ERROR);
            if(($company['digest']??null)!==($result['company_digest']??null))throw new RuntimeException('company_context_changed',409);
            $proposal=SupportContract::proposal(Core::json($result['proposal']??[]),['scope-approved']);
            $ack=$company!==null&&$j['status']==='human_required'&&$proposal['decision']==='escalate';
            if($j['status']==='human_required'&&!$ack){$this->db->commit();return ['status'=>'review_only'];}
            if((!$ack&&!in_array($proposal['decision'],['reply','clarify'],true))||($result['delivery_status']??'')!=='not_sent')throw new RuntimeException('review_required',409);
            $text=$ack?'Recibimos tu mensaje. Ticket #'.$j['source_id'].': pendiente de respuesta humana. Tu solicitud todavía no se ha resuelto.':$proposal['reply'];
            if($this->bot<1||!$this->q('SELECT id FROM users WHERE id=?',[$this->bot])->fetchColumn())throw new RuntimeException('bot_unavailable',503);
            $this->q('INSERT INTO mensajes(chat_id,user_id,contenido,tipo,file_id) VALUES(?,?,?,?,NULL)',[$chat,$this->bot,$text,'texto']);
            $id=(int)$this->db->lastInsertId();
            $this->q('UPDATE chats SET last_message_at=CURRENT_TIMESTAMP WHERE id=?',[$chat]);
            $this->q('INSERT INTO support_deliveries(job_id,message_id,chat_id,created_at) VALUES(?,?,?,?)',[$job,$id,$chat,$now]);
            $this->q('INSERT INTO support_delivery_notices(message_id,chat_id,created_at) VALUES(?,?,?)',[$id,$chat,$now]);
            ($this->queuePush)($this->db,$chat,$this->bot,$id,$text,$now);
            $result['delivery_status']='saved_to_chat';if($ack)$result['acknowledgement']=true;$result['message_id']=$id;
            $this->q("UPDATE support_pilot_jobs SET status=?,result_json=?,updated_at=? WHERE id=?",[$ack?'human_required':'delivered',Core::json($result),$now,$job]);
            $this->db->commit();return ['status'=>'saved_to_chat','message_id'=>$id,'repeat'=>false];
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    /** Retriable broadcasts use the same message ID. Never call n8n again. */
    public function flush(Closure $publish,int $limit=20):int{
        $limit=max(1,min(100,$limit));$count=0;
        $rows=$this->q('SELECT n.message_id,n.chat_id,m.user_id,m.contenido,f.deleted_at FROM support_delivery_notices n JOIN mensajes m ON m.id=n.message_id AND m.chat_id=n.chat_id LEFT JOIN chat_message_features f ON f.message_id=m.id WHERE n.published_at IS NULL ORDER BY n.created_at,n.message_id LIMIT '.$limit)->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as $r){
            if(!$r['deleted_at']){
                $payload=['type'=>'chat_message','message_id'=>(int)$r['message_id'],'id'=>(int)$r['message_id'],'chat_id'=>(int)$r['chat_id'],'user_id'=>(int)$r['user_id'],'contenido'=>$r['contenido'],'tipo'=>'texto','timestamp'=>date('c'),'user_name'=>'TuaniBot','status'=>'sent','action'=>'new_message'];
                $publish(['origin'=>'support-delivery','kind'=>'chat','chat_id'=>(int)$r['chat_id'],'payload'=>$payload]);
            }
            $this->q('UPDATE support_delivery_notices SET published_at=? WHERE message_id=? AND published_at IS NULL',[time(),$r['message_id']]);$count++;
        }
        return $count;
    }
}
