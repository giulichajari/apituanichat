<?php
declare(strict_types=1);
namespace App\Services;

use PDO;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Local database work only: deliver must persist the message/outbox in this transaction. */
final class ChatSchedule
{
    public function __construct(private PDO $db) {}

    public static function validate(array $input, int $now): array
    {
        $kind=(string)($input['kind'] ?? '');
        if (!in_array($kind,['message','reminder'],true)) throw new InvalidArgumentException('Tipo inválido');
        $body=trim((string)($input['body'] ?? ''));
        if ($body==='' || strlen($body)>16000) throw new InvalidArgumentException('Escribe un texto de hasta 16000 bytes');
        $when=filter_var($input['due_at'] ?? null,FILTER_VALIDATE_INT);
        if ($when===false || $when<$now+10 || $when>$now+366*86400) throw new InvalidArgumentException('Fecha fuera del rango permitido');
        $zone=(string)($input['timezone'] ?? '');
        if (!in_array($zone,DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),true) && $zone!=='UTC') throw new InvalidArgumentException('Zona horaria inválida');
        $chat=$kind==='message' ? filter_var($input['chat_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) : null;
        if ($kind==='message' && !$chat) throw new InvalidArgumentException('Selecciona una conversación');
        return ['kind'=>$kind,'body'=>$body,'due_at'=>(int)$when,'timezone'=>$zone,'chat_id'=>$chat];
    }

    public function create(int $owner, string $key, array $input, int $now, callable $authorize): array
    {
        if ($owner<1 || !preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$key)) throw new InvalidArgumentException('Solicitud inválida');
        // A retry after the due time must still find the original committed task.
        $existing=$this->query('SELECT * FROM chat_scheduled_tasks WHERE owner_id=? AND request_key=?',[$owner,$key])->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $normalized=['kind'=>(string)($input['kind']??''),'body'=>trim((string)($input['body']??'')),
                'due_at'=>(int)($input['due_at']??0),'timezone'=>(string)($input['timezone']??''),
                'chat_id'=>($input['kind']??'')==='message'?(int)($input['chat_id']??0):null];
            if (!hash_equals($existing['request_digest'],self::digest($normalized))) throw new RuntimeException('Solicitud reutilizada con otro contenido',409);
            return $this->publicTask($existing);
        }
        $task=self::validate($input,$now);
        $authorize($owner,$task['chat_id'],$task['kind']);
        try {
            $this->query('INSERT INTO chat_scheduled_tasks(owner_id,request_key,request_digest,kind,chat_id,body,due_at,timezone,status,attempts,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',
                [$owner,$key,self::digest($task),$task['kind'],$task['chat_id'],$task['body'],$task['due_at'],$task['timezone'],'pending',0,$now,$now]);
        } catch (\PDOException $e) {
            if ((string)$e->getCode()!=='23000') throw $e;
            $row=$this->query('SELECT * FROM chat_scheduled_tasks WHERE owner_id=? AND request_key=?',[$owner,$key])->fetch(PDO::FETCH_ASSOC);
            if (!$row || !hash_equals($row['request_digest'],self::digest($task))) throw new RuntimeException('Solicitud en conflicto',409);
            return $this->publicTask($row);
        }
        return $this->get($owner,(int)$this->db->lastInsertId());
    }

    public function get(int $owner, int $id): array
    {
        $row=$this->query('SELECT * FROM chat_scheduled_tasks WHERE id=? AND owner_id=?',[$id,$owner])->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Tarea no encontrada',404);
        return $this->publicTask($row);
    }

    public function list(int $owner, int $before=PHP_INT_MAX,?string $kind=null): array
    {
        $filter=in_array($kind,['message','reminder'],true);
        return array_map([$this,'publicTask'],$this->query('SELECT * FROM chat_scheduled_tasks WHERE owner_id=? AND id<?'.($filter?' AND kind=?':'').' ORDER BY id DESC LIMIT 50',$filter?[$owner,$before,$kind]:[$owner,$before])->fetchAll(PDO::FETCH_ASSOC));
    }

    public function reminders(int $owner,int $now): array
    {
        return $this->query("SELECT id,body,due_at FROM chat_scheduled_tasks WHERE owner_id=? AND kind='reminder' AND status='sent' AND acknowledged_at IS NULL AND due_at<=? AND due_at>? ORDER BY due_at LIMIT 20",[$owner,$now,$now-86400])->fetchAll(PDO::FETCH_ASSOC);
    }

    public function acknowledge(int $owner,int $id,int $now): void
    {
        $this->query("UPDATE chat_scheduled_tasks SET acknowledged_at=? WHERE id=? AND owner_id=? AND kind='reminder'",[$now,$id,$owner]);
    }

    public function change(int $owner, int $id, array $input, int $now, callable $authorize): array
    {
        $task=self::validate($input,$now);
        $authorize($owner,$task['chat_id'],$task['kind']);
        $current=$this->get($owner,$id);
        if ($current['kind']!==$task['kind']) throw new InvalidArgumentException('No se puede cambiar el tipo');
        $updated=$this->query("UPDATE chat_scheduled_tasks SET chat_id=?,body=?,due_at=?,timezone=?,updated_at=?,attempts=0,next_attempt_at=0,last_error=NULL WHERE id=? AND owner_id=? AND status='pending'",
            [$task['chat_id'],$task['body'],$task['due_at'],$task['timezone'],$now,$id,$owner]);
        if ($updated->rowCount()!==1 && $this->get($owner,$id)['status']!=='pending') throw new RuntimeException('La tarea ya se ejecutó o canceló',409);
        return $this->get($owner,$id);
    }

    public function cancel(int $owner, int $id, int $now): array
    {
        $current=$this->get($owner,$id);
        if ($current['status']==='cancelled') return $current;
        if ($this->query("UPDATE chat_scheduled_tasks SET status='cancelled',body='',updated_at=? WHERE id=? AND owner_id=? AND status IN ('pending','failed')",[$now,$id,$owner])->rowCount()!==1) throw new RuntimeException('La tarea ya se ejecutó',409);
        return $this->get($owner,$id);
    }

    /** A single committed task creates one message, even after worker retries. */
    public function runOne(int $now, callable $authorize, callable $deliver): bool
    {
        if ($this->db->inTransaction()) throw new RuntimeException('El worker requiere su propia transacción');
        $id=null;
        $this->db->beginTransaction();
        try {
            $lock=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $task=$this->query("SELECT * FROM chat_scheduled_tasks WHERE status='pending' AND due_at<=? AND next_attempt_at<=? ORDER BY due_at,id LIMIT 1".$lock,[$now,$now])->fetch(PDO::FETCH_ASSOC);
            if (!$task) { $this->db->commit(); return false; }
            $id=(int)$task['id'];
            $authorize((int)$task['owner_id'],$task['chat_id']===null?null:(int)$task['chat_id'],$task['kind']);
            // Network calls are forbidden in this callback; enqueue an outbox record instead.
            $result=$deliver($this->db,$task);
            $this->query("UPDATE chat_scheduled_tasks SET status='sent',result_id=?,body=CASE WHEN kind='reminder' THEN body ELSE '' END,attempts=attempts+1,updated_at=?,last_error=NULL WHERE id=?",[$result,$now,$id]);
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ($id!==null) {
                $permanent=in_array((int)$e->getCode(),[403,404],true);
                $status=$permanent ? "'failed'" : "CASE WHEN attempts>=4 THEN 'failed' ELSE 'pending' END";
                $this->query("UPDATE chat_scheduled_tasks SET status=$status,attempts=attempts+1,next_attempt_at=?,last_error=?,updated_at=? WHERE id=? AND status='pending'",
                    [$now+60,$permanent?'access_denied':'delivery_unavailable',$now,$id]);
            }
            throw $e;
        }
    }

    private static function digest(array $task): string { return hash('sha256',json_encode($task,JSON_THROW_ON_ERROR)); }
    private function query(string $sql,array $params): \PDOStatement { $s=$this->db->prepare($sql);$s->execute($params);return $s; }
    private function publicTask(array $task): array { unset($task['request_digest'],$task['request_key']);return $task; }
}
