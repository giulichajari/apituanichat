<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use PDO;
use Closure;
use RuntimeException;

/** Durable, bounded relay. The support API owns generation/delivery idempotency. */
final class SupportRelay
{
    public const URL='https://n8n.tuanichat.com/webhook/tuanichat-soporte-entrada';
    public function __construct(private PDO $db,private int $bot,private Closure $send){}
    private function q(string $sql,array $args=[]):\PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
    private function mysql():bool{return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';}
    private function lock():string{return $this->mysql()?' FOR UPDATE':'';}
    private function source(int $agent,int $message):array|false{
        $epoch=$this->mysql()?'UNIX_TIMESTAMP(m.enviado_en)':"CAST(strftime('%s',m.enviado_en) AS INTEGER)";
        return $this->q("SELECT m.id,m.chat_id,m.user_id,m.tipo,m.file_id,m.contenido,$epoch AS sent_at,c.agent_id,c.bot_active,a.user_id AS owner_id,a.secret,a.webhook_url,p.enabled AS pilot_enabled,p.auto_after,p.auto_delivery,r.enabled AS relay_enabled,r.chat_id AS relay_chat,r.start_id,cl.user_id AS client_user,cl.bot_id AS client_bot,cl.start_id AS client_start,cl.enabled AS client_enabled,x.deleted_at,x.view_once,x.attachment_id FROM mensajes m JOIN chats c ON c.id=m.chat_id JOIN agents a ON a.id=c.agent_id JOIN support_pilot_agents p ON p.agent_id=a.id JOIN support_relay_agents r ON r.agent_id=a.id LEFT JOIN support_company_clients cl ON cl.chat_id=c.id AND cl.agent_id=a.id LEFT JOIN chat_message_features x ON x.message_id=m.id WHERE m.id=? AND a.id=?",[$message,$agent])->fetch(PDO::FETCH_ASSOC);
    }
    private function eligible(array|false $m,int $now):bool{
        if(!$m||$this->bot<1||!(int)$m['relay_enabled']||!(int)$m['pilot_enabled']||!(int)$m['bot_active']||$m['webhook_url']!==self::URL||strlen((string)$m['secret'])<32)return false;
        $client=(int)$m['chat_id']!==(int)$m['relay_chat'];
        if($client&&(!(int)$m['client_enabled']||(int)$m['client_user']!==(int)$m['user_id']||(int)$m['client_bot']!==$this->bot||(int)$m['id']<=(int)$m['client_start']))return false;
        if((!$client&&(int)$m['id']<=(int)$m['start_id'])||(int)$m['user_id']===$this->bot||$m['tipo']!=='texto'||$m['file_id']||$m['attachment_id']||$m['deleted_at']||$m['view_once']||trim((string)$m['contenido'])===''||strlen($m['contenido'])>4000)return false;
        if((int)$m['sent_at']<$now-600||(int)$m['sent_at']>$now+60||((int)$m['auto_delivery']&&(int)$m['sent_at']<=(int)$m['auto_after']))return false;
        foreach($client?[$this->bot,(int)$m['user_id']]:[(int)$m['owner_id'],(int)$m['user_id']] as $u){
            if(!$this->q('SELECT 1 FROM chat_usuarios c JOIN users u ON u.id=c.user_id WHERE c.chat_id=? AND c.user_id=?',[$m['chat_id'],$u])->fetchColumn())return false;
            if($this->q('SELECT hidden_at FROM chat_message_users WHERE message_id=? AND user_id=?',[$m['id'],$u])->fetchColumn())return false;
        }
        return true;
    }
    /** Inspect a bounded batch, including paused/ineligible messages so they are not replayed later. */
    public function collect(int $now):int{
        $count=0;
        foreach($this->q('SELECT agent_id,start_id,chat_id FROM support_relay_agents WHERE enabled=1')->fetchAll(PDO::FETCH_ASSOC) as $a){
            $rows=$this->q('SELECT m.id FROM mensajes m JOIN chats c ON c.id=m.chat_id WHERE c.agent_id=? AND ((c.id=? AND m.id>?) OR EXISTS(SELECT 1 FROM support_company_clients cc WHERE cc.chat_id=c.id AND cc.agent_id=c.agent_id AND cc.enabled=1 AND m.id>cc.start_id)) AND m.user_id<>? AND NOT EXISTS(SELECT 1 FROM support_relay_jobs j WHERE j.agent_id=? AND j.message_id=m.id) ORDER BY m.id LIMIT 100',[$a['agent_id'],$a['chat_id'],$a['start_id'],$this->bot,$a['agent_id']])->fetchAll(PDO::FETCH_COLUMN);
            foreach($rows as $id){
                $m=$this->source((int)$a['agent_id'],(int)$id);if(!$m)continue;
                $status=$this->eligible($m,$now)?'pending':'skipped';
                $prefix=$this->mysql()?'INSERT IGNORE':'INSERT OR IGNORE';
                $count+=$this->q("$prefix INTO support_relay_jobs(agent_id,message_id,chat_id,status,attempts,next_at,lease_until,created_at,error_code) VALUES(?,?,?,?,0,?,0,?,?)",[$a['agent_id'],$id,$m['chat_id'],$status,$now,$now,$status==='skipped'?'not_eligible':''])->rowCount();
            }
        }
        return $count;
    }
    /** One HTTP call per tick; transactional lease survives worker termination. */
    public function tick(int $now):array{
        $this->db->beginTransaction();
        try{
            $job=$this->q("SELECT * FROM support_relay_jobs WHERE (status='pending' AND next_at<=?) OR (status='processing' AND lease_until<=?) ORDER BY created_at,message_id LIMIT 1".$this->lock(),[$now,$now])->fetch(PDO::FETCH_ASSOC);
            if(!$job){$this->db->commit();return ['status'=>'idle'];}
            $m=$this->source((int)$job['agent_id'],(int)$job['message_id']);
            if(!$this->eligible($m,$now)||(int)$m['chat_id']!==(int)$job['chat_id']||(int)$job['attempts']>=3){
                $this->q("UPDATE support_relay_jobs SET status='review',error_code='expired_paused_or_exhausted',lease_until=0 WHERE agent_id=? AND message_id=?",[$job['agent_id'],$job['message_id']]);$this->db->commit();return ['status'=>'review'];
            }
            $attempt=(int)$job['attempts']+1;
            $this->q("UPDATE support_relay_jobs SET status='processing',attempts=?,lease_until=? WHERE agent_id=? AND message_id=?",[$attempt,$now+120,$job['agent_id'],$job['message_id']]);
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        try{$response=($this->send)((string)$m['secret'],(int)$job['chat_id'],(int)$job['message_id']);}
        catch(\Throwable $e){$response=['http'=>0,'body'=>null];}
        $http=(int)($response['http']??0);$body=$response['body']??null;
        $valid=$http===200&&is_array($body)&&($body['success']??false)===true&&preg_match('/^[a-f0-9]{32}$/D',(string)($body['job']['id']??''))&&in_array($body['job']['status']??'', ['draft','delivered','human_required','needs_review','blocked','processing','human_answered','human_resolved'],true);
        $status='review';$code='invalid_response';
        if($valid&&$body['job']['status']!=='processing'){$status='done';$code='';}
        elseif($http===0||$http>=500||($valid&&$body['job']['status']==='processing')){$status=$attempt<3?'pending':'review';$code=$http===0?'network_error':($valid?'processing':'upstream_error');}
        elseif(in_array($http,[401,403,404,429],true))$code='http_'.$http;
        // CAS prevents a delayed process from overwriting a newer lease.
        $this->q('UPDATE support_relay_jobs SET status=?,error_code=?,next_at=?,lease_until=0 WHERE agent_id=? AND message_id=? AND attempts=? AND status=\'processing\'',[$status,$code,$now+120,$job['agent_id'],$job['message_id'],$attempt]);
        return ['status'=>$status,'http'=>$http];
    }
    public static function post(string $secret,int $chat,int $message):array{
        if(strlen($secret)<32||preg_match('/[\r\n]/',$secret)||$chat<1||$message<1)throw new RuntimeException('invalid_request');
        $curl=curl_init(self::URL);$raw='';
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Tuani-Support-Token: '.$secret],CURLOPT_POSTFIELDS=>json_encode(['chat_id'=>$chat,'message_id'=>$message],JSON_THROW_ON_ERROR),CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>75,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_WRITEFUNCTION=>static function($c,string $chunk)use(&$raw):int{if(strlen($raw)+strlen($chunk)>65536)return 0;$raw.=$chunk;return strlen($chunk);}]);
        $ok=curl_exec($curl);$http=$ok===false?0:(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
        return ['http'=>$http,'body'=>json_decode($raw,true,32)];
    }
}
