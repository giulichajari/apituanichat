<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use PDO;
use RuntimeException;
use Closure;

/** Authenticated n8n intake and durable proposal inbox. Does NOT send chat messages. */
final class SupportPilot
{
    public function __construct(private PDO $db,private int $botId,private Closure $generate){}
    private function q(string $sql,array $args=[]): \PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
    private function suffix():string{return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';}
    private function tx(Closure $work):mixed{
        if($this->db->inTransaction())throw new RuntimeException('nested_transaction',500);
        $this->db->beginTransaction();try{$result=$work();$this->db->commit();return $result;}
        catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function settings(int $owner,int $agent):array{
        $row=$this->q('SELECT p.enabled,p.daily_limit,p.used_day,p.used_count,p.auto_delivery FROM support_pilot_agents p JOIN agents a ON a.id=p.agent_id WHERE p.agent_id=? AND a.user_id=?',[$agent,$owner])->fetch(PDO::FETCH_ASSOC);
        if($owner<1||!$row)throw new RuntimeException('forbidden',403);
        return ['enabled'=>(bool)$row['enabled'],'daily_limit'=>(int)$row['daily_limit'],
            'used_today'=>$row['used_day']===gmdate('Y-m-d')?(int)$row['used_count']:0,'auto_delivery'=>(bool)$row['auto_delivery']];
    }
    public function updateSettings(int $owner,int $agent,bool $enabled,int $limit,bool $auto):array{
        if($limit<1||$limit>20)throw new RuntimeException('invalid_limit',400);
        return $this->tx(function()use($owner,$agent,$enabled,$limit,$auto){
            $row=$this->q('SELECT a.user_id,p.auto_delivery,p.enabled FROM support_pilot_agents p JOIN agents a ON a.id=p.agent_id WHERE p.agent_id=?'.$this->suffix(),[$agent])->fetch(PDO::FETCH_ASSOC);
            if($owner<1||!$row||(int)$row['user_id']!==$owner)throw new RuntimeException('forbidden',403);
            $this->q('UPDATE support_pilot_agents SET enabled=?,daily_limit=? WHERE agent_id=?',[$enabled?1:0,$limit,$agent]);
            if($auto&&(!(int)$row['auto_delivery']||(!(int)$row['enabled']&&$enabled)))$this->q('UPDATE support_pilot_agents SET auto_after=? WHERE agent_id=?',[time(),$agent]);
            $this->q('UPDATE support_pilot_agents SET auto_delivery=? WHERE agent_id=?',[$auto?1:0,$agent]);
            return $this->settings($owner,$agent);
        });
    }
    private function source(int $agent,int $chat,int $message,string $secret,bool $locked):array{
        $a=$this->q('SELECT a.id,a.user_id,a.secret,p.enabled,p.daily_limit,p.used_day,p.used_count FROM agents a JOIN support_pilot_agents p ON p.agent_id=a.id WHERE a.id=?'.($locked?$this->suffix():''),[$agent])->fetch(PDO::FETCH_ASSOC);
        if(!$a||$secret===''||!is_string($a['secret'])||strlen($a['secret'])<32||!hash_equals($a['secret'],$secret))throw new RuntimeException('unauthorized',401);
        if(!(int)$a['enabled'])throw new RuntimeException('pilot_disabled',403);
        $c=$this->q('SELECT agent_id,bot_active FROM chats WHERE id=?'.($locked?$this->suffix():''),[$chat])->fetch(PDO::FETCH_ASSOC);
        if(!$c||(int)$c['agent_id']!==$agent||!(int)$c['bot_active'])throw new RuntimeException('chat_not_available',403);
        $m=$this->q('SELECT m.id,m.user_id,m.contenido,m.tipo,m.file_id,x.deleted_at,x.view_once,x.attachment_id FROM mensajes m LEFT JOIN chat_message_features x ON x.message_id=m.id WHERE m.id=? AND m.chat_id=?'.($locked?$this->suffix():''),[$message,$chat])->fetch(PDO::FETCH_ASSOC);
        if(!$m||$this->botId<1||(int)$m['user_id']===$this->botId||$m['tipo']!=='texto'||$m['file_id']||$m['attachment_id']||$m['deleted_at']||$m['view_once']||trim((string)$m['contenido'])===''||strlen($m['contenido'])>4000)throw new RuntimeException('message_not_eligible',422);
        $company=(new SupportCompanyBinding($this->db))->resolve($agent,$chat,(int)$a['user_id'],(int)$m['user_id'],$message);
        if(($company['client']??false)&&$company['bot_id']!==$this->botId)throw new RuntimeException('bot_changed',403);
        foreach(($company['client']??false)?[$this->botId,(int)$m['user_id']]:[(int)$a['user_id'],(int)$m['user_id']] as $member)
            if(!$this->q('SELECT 1 FROM chat_usuarios c JOIN users u ON u.id=c.user_id WHERE c.chat_id=? AND c.user_id=?',[$chat,$member])->fetchColumn())throw new RuntimeException('membership_required',403);
        if($this->q('SELECT hidden_at FROM chat_message_users WHERE message_id=? AND user_id=?',[$message,$m['user_id']])->fetchColumn())throw new RuntimeException('message_not_eligible',422);
        return ['agent'=>$a,'message'=>$m,'digest'=>hash('sha256',$m['contenido']),'company'=>$company];
    }
    public function receive(int $agent,int $chat,int $message,string $secret,int $now):array{
        if(min($agent,$chat,$message)<1)throw new RuntimeException('invalid_reference',400);
        $claim=$this->tx(function()use($agent,$chat,$message,$secret,$now){
            $source=$this->source($agent,$chat,$message,$secret,true);
            $existing=$this->q('SELECT id,status,claim_until FROM support_pilot_jobs WHERE agent_id=? AND source_id=?',[$agent,$message])->fetch(PDO::FETCH_ASSOC);
            if($existing){
                if($existing['status']==='processing'&&(int)$existing['claim_until']<=$now){
                    $this->q("UPDATE support_pilot_jobs SET status='needs_review',error_code='execution_interrupted',updated_at=? WHERE id=?",[$now,$existing['id']]);$existing['status']='needs_review';
                }
                return ['repeat'=>true,'id'=>$existing['id'],'status'=>$existing['status']];
            }
            if(($source['company']['client']??false)&&(int)$this->q('SELECT COUNT(*) FROM support_pilot_jobs WHERE agent_id=? AND chat_id=? AND actor_id=? AND created_at>=?',[$agent,$chat,$source['message']['user_id'],(int)(floor($now/86400)*86400)])->fetchColumn()>=5)throw new RuntimeException('client_daily_limit',429);
            $day=gmdate('Y-m-d',$now);$used=$source['agent']['used_day']===$day?(int)$source['agent']['used_count']:0;
            $limit=(int)$source['agent']['daily_limit'];if($limit<1||$used>=$limit)throw new RuntimeException('daily_limit_reached',429);
            $this->q('UPDATE support_pilot_agents SET used_day=?,used_count=? WHERE agent_id=?',[$day,$used+1,$agent]);
            $id=bin2hex(random_bytes(16));$lease=bin2hex(random_bytes(32));
            $this->q("INSERT INTO support_pilot_jobs(id,agent_id,chat_id,source_id,actor_id,owner_id,source_digest,status,claim_key,claim_until,created_at,updated_at) VALUES(?,?,?,?,?,?,?,'processing',?,?,?,?)",[$id,$agent,$chat,$message,$source['message']['user_id'],$source['agent']['user_id'],$source['digest'],$lease,$now+90,$now,$now]);
            return ['repeat'=>false,'id'=>$id,'lease'=>$lease,'source'=>$source];
        });
        if($claim['repeat'])return array_intersect_key($claim,array_flip(['id','status','repeat']));
        try{
            $result=($this->generate)(['id'=>$claim['id'],'tenant_id'=>(int)$claim['source']['agent']['user_id'],
                'actor_id'=>(int)$claim['source']['message']['user_id'],'agent_id'=>$agent],$claim['source']['message']['contenido'],$claim['source']['company']);
            // Generation must return the contract, usage and explicit non-delivery marker.
            $proposal=SupportContract::proposal(Core::json($result['proposal']??[]),['scope-approved']);
            if(($result['delivery_status']??'')!=='not_sent')throw new RuntimeException('invalid_delivery_status');
            foreach(['prompt_tokens','completion_tokens','total_tokens'] as $k)
                if(!is_int($result['usage'][$k]??null)||$result['usage'][$k]<0)throw new RuntimeException('invalid_usage');
            $stored=Core::json(['proposal'=>$proposal,'usage'=>$result['usage'],'delivery_status'=>'not_sent','company_digest'=>$claim['source']['company']['digest']??null],20000);
        }catch(\Throwable $e){
            // Never persist raw exceptions: provider bodies may include private data.
            $allowed=['provider_network_error','provider_invalid_body','provider_invalid_json','provider_incomplete_or_refused','provider_missing_usage','provider_invalid_usage','provider_invalid_proposal','curl_unavailable'];
            $code=in_array($e->getMessage(),$allowed,true)||preg_match('/^provider_http_[1-5][0-9]{2}$/D',$e->getMessage())?$e->getMessage():'generation_failed';
            $this->tx(function()use($claim,$now,$code){$this->q("UPDATE support_pilot_jobs SET status='needs_review',error_code=?,updated_at=? WHERE id=? AND status='processing' AND claim_key=?",[$code,$now,$claim['id'],$claim['lease']]);});
            return ['id'=>$claim['id'],'status'=>'needs_review','repeat'=>false];
        }
        return $this->tx(function()use($agent,$chat,$message,$secret,$now,$claim,$stored,$proposal){
            try{$current=$this->source($agent,$chat,$message,$secret,true);
                if(($current['company']['digest']??null)!==($claim['source']['company']['digest']??null)||!hash_equals($claim['source']['digest'],$current['digest'])||(int)$current['agent']['user_id']!==(int)$claim['source']['agent']['user_id']||
                    (int)$current['message']['user_id']!==(int)$claim['source']['message']['user_id'])throw new RuntimeException('source_changed');
            }catch(RuntimeException $e){
                $this->q("UPDATE support_pilot_jobs SET status='blocked',error_code='source_changed',updated_at=? WHERE id=? AND status='processing' AND claim_key=?",[$now,$claim['id'],$claim['lease']]);
                return ['id'=>$claim['id'],'status'=>'blocked','repeat'=>false];
            }
            $status=$proposal['decision']==='escalate'?'human_required':'draft';
            $updated=$this->q('UPDATE support_pilot_jobs SET status=?,result_json=?,updated_at=? WHERE id=? AND status=\'processing\' AND claim_key=?',[$status,$stored,$now,$claim['id'],$claim['lease']]);
            if($updated->rowCount()!==1)throw new RuntimeException('claim_lost',409);
            return ['id'=>$claim['id'],'status'=>$status,'repeat'=>false];
        });
    }
    public function inbox(int $owner,int $agent):array{
        if($owner<1||!$this->q('SELECT id FROM agents WHERE id=? AND user_id=?',[$agent,$owner])->fetchColumn())throw new RuntimeException('forbidden',403);
        $this->q("UPDATE support_pilot_jobs SET status='needs_review',error_code='execution_interrupted',updated_at=? WHERE agent_id=? AND owner_id=? AND status='processing' AND claim_until<=?",[time(),$agent,$owner,time()]);
        return $this->q('SELECT j.id,j.chat_id,j.source_id,j.status,j.result_json,j.error_code,j.created_at FROM support_pilot_jobs j JOIN chats c ON c.id=j.chat_id AND c.agent_id=j.agent_id JOIN chat_usuarios member ON member.chat_id=j.chat_id AND member.user_id=j.owner_id JOIN chat_usuarios sender ON sender.chat_id=j.chat_id AND sender.user_id=j.actor_id JOIN mensajes m ON m.id=j.source_id AND m.chat_id=j.chat_id LEFT JOIN chat_message_features f ON f.message_id=m.id LEFT JOIN chat_message_users hidden ON hidden.message_id=m.id AND hidden.user_id=j.owner_id WHERE j.agent_id=? AND j.owner_id=? AND f.deleted_at IS NULL AND COALESCE(f.view_once,0)=0 AND hidden.hidden_at IS NULL ORDER BY j.created_at DESC,j.id DESC LIMIT 50',[$agent,$owner])->fetchAll(PDO::FETCH_ASSOC);
    }
}
