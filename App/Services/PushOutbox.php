<?php
namespace App\Services;

use PDO;

/** Durable per-device work. Network delivery belongs to the CLI worker, never Ratchet. */
final class PushOutbox
{
    public function __construct(private PDO $db) {}

    public function enqueue(int $userId, string $token, array $data, int $now): bool
    {
        $type = (string) ($data['type'] ?? '');
        $id = (string) ($data['message_id'] ?? $data['session_id'] ?? $data['event_id'] ?? '');
        if ($userId < 1 || $token === '' || !in_array($type, ['new_message', 'incoming_call', 'ride_update', 'reminder'], true) || $id === '') {
            throw new \InvalidArgumentException('Push requiere destinatario y evento estable');
        }
        $ttl = $type === 'incoming_call' ? 60 : ($type === 'ride_update' ? 300 : 86400);
        $expires = min($now + $ttl, (int) ($data['expires_at'] ?? $now + $ttl));
        if ($expires <= $now) return false;
        $data['recipient_id'] = (string) $userId;
        $data['expires_at'] = (string) $expires;
        // Never persist media negotiation, auth tokens or caller-controlled URLs in push.
        $data = array_intersect_key($data, array_flip(['type', 'message_id', 'session_id', 'chat_id', 'sender_name', 'caller_name', 'from', 'body', 'call_type', 'recipient_id', 'expires_at', 'event_id', 'ride_id', 'ride_status', 'title']));
        $payload = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($payload) > 3000) throw new \InvalidArgumentException('Notificación demasiado grande');
        $hash = hash('sha256', $token);
        $key = hash('sha256', json_encode([$userId, $hash, $type, $id], JSON_THROW_ON_ERROR));
        try {
            $sql = 'INSERT INTO push_outbox(event_key,user_id,token_hash,payload,expires_at,next_attempt_at,created_at) VALUES(?,?,?,?,?,?,?)';
            $this->db->prepare($sql)->execute([$key, $userId, $hash, $payload, $expires, $now, $now]);
            return true;
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '23000') throw $e;
            $stmt = $this->db->prepare('SELECT id FROM push_outbox WHERE event_key=?');
            $stmt->execute([$key]);
            if (!$stmt->fetchColumn()) throw $e;
            return false;
        }
    }

    public function claim(int $now): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM push_outbox WHERE status='pending' AND next_attempt_at<=? AND claimed_until<=? AND expires_at>? ORDER BY id LIMIT 1");
        $stmt->execute([$now, $now, $now]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$job) return null;
        $lease = bin2hex(random_bytes(16));
        $stmt = $this->db->prepare("UPDATE push_outbox SET lease_key=?,claimed_until=?,attempts=attempts+1 WHERE id=? AND status='pending' AND claimed_until<=? AND expires_at>?");
        $stmt->execute([$lease, $now + 90, $job['id'], $now, $now]);
        if ($stmt->rowCount() !== 1) return null;
        $job['lease_key'] = $lease;
        $job['attempts'] = (int) $job['attempts'] + 1;
        return $job;
    }

    public function finish(array $job, bool $sent, bool $permanent, int $now, int $retryAfter = 0): void
    {
        $status = $sent ? 'sent' : (($permanent || $job['attempts'] >= 8 || $job['expires_at'] <= $now) ? 'failed' : 'pending');
        $delay = max($retryAfter, min(300, 2 ** min(8, (int) $job['attempts'])));
        $stmt = $this->db->prepare('UPDATE push_outbox SET status=?,next_attempt_at=?,claimed_until=0,lease_key=NULL WHERE id=? AND lease_key=?');
        $stmt->execute([$status, $now + $delay, $job['id'], $job['lease_key']]);
    }

    public function expire(int $now): void
    {
        $this->db->prepare("UPDATE push_outbox SET status='expired',lease_key=NULL WHERE status='pending' AND expires_at<=?")->execute([$now]);
        // Keep limited operational history; no message archive belongs in the delivery queue.
        $this->db->prepare("DELETE FROM push_outbox WHERE status<>'pending' AND expires_at<?")->execute([$now - 604800]);
    }
}
