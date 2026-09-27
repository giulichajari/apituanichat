<?php
namespace App\Services;

use PDO;

/** Shared state with optimistic revisions. No instance may resurrect an ended call. */
final class CallRegistry implements \ArrayAccess, \IteratorAggregate
{
    public function __construct(private PDO $db) {}
    public function offsetExists(mixed $id): bool { return $this->offsetGet($id) !== null; }
    public function offsetGet(mixed $id): ?array
    {
        $stmt = $this->db->prepare('SELECT state,revision FROM realtime_calls WHERE call_id=?');
        $stmt->execute([(string)$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $call = json_decode($row['state'], true, 512, JSON_THROW_ON_ERROR);
        $call['_revision'] = (int) $row['revision'];
        return $call;
    }
    public static function isRinging(array $call, int $now): bool
    {
        return ($call['status'] ?? '') === 'ringing' && (int)$call['created_at'] + 60 > $now;
    }
    public function offsetSet(mixed $id, mixed $call): void
    {
        $owns = !$this->db->inTransaction();
        if ($owns) $this->db->beginTransaction();
        try {
            $this->persistWithHistory($id, $call);
            $saved = $this->offsetGet($id);
            if ($saved) (new ChatCallHistory($this->db))->record($saved);
            if ($owns) $this->db->commit();
        } catch (\Throwable $e) {
            if ($owns && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
    private function persistWithHistory(mixed $id, mixed $call): void
    {
        if (!is_array($call) || !preg_match('/^[a-zA-Z0-9_.:-]{1,120}$/D', (string)$id) || ($call['call_id'] ?? null) !== $id) throw new \InvalidArgumentException('ID de llamada inválido');
        if (strlen(json_encode($call,JSON_THROW_ON_ERROR)) > 262144) throw new \InvalidArgumentException('Señalización demasiado grande');
        if (!in_array($call['status'] ?? '', ['ringing','accepted','ended'], true)) throw new \InvalidArgumentException('Estado inválido');
        $old = $this->offsetGet($id);
        if (!$old) {
            if ($call['status'] !== 'ringing' || isset($call['_revision'])) throw new \RuntimeException('Llamada inexistente');
            $stmt = $this->db->prepare('INSERT INTO realtime_calls(call_id,caller_id,callee_id,status,revision,updated_at,state) VALUES(?,?,?,?,1,?,?)');
            $stmt->execute([$id,$call['caller_id'],$call['callee_id'],$call['status'],time(),json_encode($call,JSON_THROW_ON_ERROR)]);
            return;
        }
        foreach (['caller_id','callee_id','chat_id','created_at'] as $key) {
            if ((string)($call[$key] ?? '') !== (string)($old[$key] ?? '')) throw new \RuntimeException('Identidad de llamada inmutable');
        }
        if ($old['status'] === 'ended' && $call['status'] !== 'ended') throw new \RuntimeException('Llamada finalizada');
        if ($old['status'] === 'accepted' && ($call['status'] === 'ringing' ||
            ($call['status'] === 'accepted' && [($call['callee_instance'] ?? null),($call['callee_conn_id'] ?? null)] !== [($old['callee_instance'] ?? null),($old['callee_conn_id'] ?? null)]))) throw new \RuntimeException('Llamada ya atendida');
        if ($old['status'] === 'ringing' && $call['status'] !== 'ended' && !self::isRinging($old, time())) throw new \RuntimeException('Llamada expirada');
        $revision = (int)($call['_revision'] ?? 0);
        unset($call['_revision']);
        $stmt = $this->db->prepare('UPDATE realtime_calls SET state=?,status=?,updated_at=?,revision=revision+1 WHERE call_id=? AND revision=?');
        $stmt->execute([json_encode($call,JSON_THROW_ON_ERROR),$call['status'],time(),$id,$revision]);
        if ($stmt->rowCount() !== 1) throw new \RuntimeException('La llamada cambió; recuperar estado actual');
    }
    public function offsetUnset(mixed $id): void
    {
        $this->db->prepare("DELETE FROM realtime_calls WHERE call_id=? AND status='ended' AND updated_at<?")->execute([(string)$id,time()-300]);
    }
    public function getIterator(): \Traversable
    {
        $stmt = $this->db->query('SELECT call_id,state,revision FROM realtime_calls');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $call = json_decode($row['state'], true, 512, JSON_THROW_ON_ERROR);
            $call['_revision'] = (int)$row['revision'];
            yield $row['call_id'] => $call;
        }
    }
    public function incoming(int $userId): array
    {
        $stmt = $this->db->prepare("SELECT call_id FROM realtime_calls WHERE callee_id=? AND status='ringing' ORDER BY updated_at DESC LIMIT 20");
        $stmt->execute([$userId]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $call = $this->offsetGet($id);
            if ($call && self::isRinging($call,time())) $result[] = $call;
        }
        return $result;
    }
    public function expireRinging(string $id, int $now): ?array
    {
        $call = $this->offsetGet($id);
        if (!$call || $call['status'] !== 'ringing' || self::isRinging($call, $now)) return null;
        $call['status'] = 'ended';
        $call['reason'] = 'timeout';
        $call['ended_by'] = $call['caller_id'];
        $call['updated_at'] = $now;
        try { $this->offsetSet($id, $call); }
        catch (\RuntimeException $e) { return null; } // An answer or another cleanup won the revision.
        return $call;
    }
    public function addCandidate(string $id, int $sender, array $candidate): void
    {
        for ($retry=0; $retry<3; $retry++) {
            $call = $this->offsetGet($id);
            if (!$call || !in_array($sender,[(int)$call['caller_id'],(int)$call['callee_id']],true) || $call['status'] === 'ended') throw new \RuntimeException('Señal no autorizada');
            $key = $sender === (int)$call['caller_id'] ? 'caller_ice' : 'callee_ice';
            $items = $call[$key] ?? [];
            if (in_array($candidate,$items,true)) return;
            if (count($items)>=64 || strlen(json_encode($candidate,JSON_THROW_ON_ERROR))>4096) throw new \RuntimeException('Límite de candidatos');
            $call[$key][] = $candidate;
            try { $this->offsetSet($id,$call); return; } catch (\RuntimeException $e) { if ($retry===2) throw $e; }
        }
    }
}
