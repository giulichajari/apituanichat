<?php
namespace App\Models;

use App\Configs\Database;
use PDO;

class SignalModel
{
    private PDO $db;
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    /** Legacy HTTP transport: identity must come from the authenticated request. */
    public function exchange(string $sessionId, int $userId, string $type, bool $write, mixed $payload = null): mixed
    {
        if (!preg_match('/^[a-zA-Z0-9_.:-]{1,120}$/D', $sessionId) || $userId < 1
            || !in_array($type, ['offer', 'answer', 'candidate'], true)) {
            throw new \InvalidArgumentException('Invalid signal');
        }
        $encoded = null;
        if ($write) {
            if (is_object($payload)) $payload = (array)$payload;
            if (!is_array($payload) && !is_string($payload)) throw new \InvalidArgumentException('Invalid payload');
            if ($payload === '' || $payload === []) throw new \InvalidArgumentException('Empty payload');
            // Store valid JSON even for a raw SDP string.
            try { $encoded = json_encode($payload, JSON_THROW_ON_ERROR); }
            catch (\JsonException $e) { throw new \InvalidArgumentException('Invalid payload'); }
            if (strlen($encoded) > ($type === 'candidate' ? 4096 : 65536)) throw new \LengthException('Signal too large');
        }
        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            // Serialize authorization and access with updates/termination of this registered call.
            $stmt = $this->db->prepare('SELECT caller_id,callee_id,status,state FROM realtime_calls WHERE call_id=? FOR UPDATE');
            $stmt->execute([$sessionId]);
            $call = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$call || !in_array($userId, [(int)$call['caller_id'], (int)$call['callee_id']], true)) {
                throw new \DomainException('Call unavailable', 404);
            }
            $state = json_decode($call['state'], true, 512, JSON_THROW_ON_ERROR);
            if (!in_array($call['status'], ['ringing', 'accepted'], true)
                || ($call['status'] === 'ringing' && (int)($state['created_at'] ?? 0) + 60 <= time())) {
                throw new \DomainException('Call no longer active', 409);
            }
            if ($write && (($type === 'offer' && $userId !== (int)$call['caller_id'])
                || ($type === 'answer' && $userId !== (int)$call['callee_id']))) {
                throw new \DomainException('Invalid signal role', 403);
            }
            if ($write) {
                $stmt = $this->db->prepare('SELECT COUNT(*) FROM webrtc_signaling WHERE session_id=?');
                $stmt->execute([$sessionId]);
                if ((int)$stmt->fetchColumn() >= 160) throw new \DomainException('Signal limit reached', 429);
                $stmt = $this->db->prepare('INSERT INTO webrtc_signaling(session_id,type,payload) VALUES(?,?,?)');
                $stmt->execute([$sessionId, $type, $encoded]);
                $result = true;
            } else {
                $stmt = $this->db->prepare($type === 'candidate'
                    ? 'SELECT payload FROM webrtc_signaling WHERE session_id=? AND type=? ORDER BY id ASC LIMIT 160'
                    : 'SELECT payload FROM webrtc_signaling WHERE session_id=? AND type=? ORDER BY id DESC LIMIT 1');
                $stmt->execute([$sessionId, $type]);
                $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $values = array_map(static fn($value) => json_decode($value, true, 512, JSON_THROW_ON_ERROR), $rows);
                $result = $type === 'candidate' ? $values : ($values[0] ?? null);
            }
            if ($own) $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
