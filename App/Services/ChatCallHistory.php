<?php
declare(strict_types=1);
namespace App\Services;
use PDO;
final class ChatCallHistory
{
    public function __construct(private PDO $db){}
    public function record(array $call): void {
        if(empty($call['call_id'])||empty($call['caller_id'])||empty($call['callee_id']))return;
        $id=$call['call_id'];$now=time();$started=(int)($call['created_at']??$now);$state=$call['status']??'ringing';$rev=(int)($call['_revision']??0);
        $s=$this->db->prepare('SELECT * FROM chat_call_history WHERE call_id=?');$s->execute([$id]);$old=$s->fetch(PDO::FETCH_ASSOC);
        $answered=$old['answered_at']??($state==='accepted'?$now:null);$ended=$state==='ended'?($old['ended_at']??$now):null;
        if(!$old){
            try{$this->db->prepare('INSERT INTO chat_call_history(call_id,caller_id,callee_id,call_type,status,started_at,answered_at,ended_at,reason,revision) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$id,$call['caller_id'],$call['callee_id'],$call['call_type']??'audio',$state,$started,$answered,$ended,substr((string)($call['reason']??''),0,80),$rev]);return;}
            catch(\PDOException $e){if((string)$e->getCode()!=='23000')throw $e;}
        }
        $this->db->prepare('UPDATE chat_call_history SET status=?,answered_at=COALESCE(answered_at,?),ended_at=?,reason=?,revision=? WHERE call_id=? AND revision<=?')->execute([$state,$answered,$ended,substr((string)($call['reason']??''),0,80),$rev,$id,$rev]);
    }
    public function list(int $user,int $before=PHP_INT_MAX,string $beforeCall=''): array {
        $s=$this->db->prepare('SELECT h.*,a.name AS caller_name,b.name AS callee_name FROM chat_call_history h LEFT JOIN users a ON a.id=h.caller_id LEFT JOIN users b ON b.id=h.callee_id WHERE (h.caller_id=? OR h.callee_id=?) AND (h.started_at<? OR (h.started_at=? AND h.call_id<?)) ORDER BY h.started_at DESC,h.call_id DESC LIMIT 50');$s->execute([$user,$user,$before,$before,$beforeCall]);return $s->fetchAll(PDO::FETCH_ASSOC);
    }
}
