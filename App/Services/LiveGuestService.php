<?php
namespace App\Services;

/** Short-lived, authenticated signaling; no camera/media or credentials are stored. */
class LiveGuestService
{
    public function exchange(int $postId, int $hostId, int $actor, array $body): array
    {
        $redis = new \Redis();
        $redis->connect('127.0.0.1', 6379, 1.0);
        $redis->setOption(\Redis::OPT_READ_TIMEOUT, 2.0);
        $key = 'tuani:live:guest:' . $postId;
        $lock = $key . ':lock';
        $owner = bin2hex(random_bytes(8));
        $locked = false;
        try {
            $locked = (bool) $redis->set($lock, $owner, ['nx', 'ex' => 5]);
            if (!$locked) throw new \RuntimeException('Reintenta en un momento.', 429);
            $state = json_decode($redis->get($key) ?: 'null', true);
            $now = time();
            if ($state && ($state['expires'] < $now || ($state['status'] === 'accepted'
                && min($state['host_seen'], $state['guest_seen']) < $now - 35))) $state = null;
            $action = $body['action'] ?? 'sync';
            if (!in_array($action, ['sync', 'invite', 'accept', 'leave', 'signal'], true)) throw new \RuntimeException('Acción inválida.', 400);
            if ($action === 'invite') {
                if ($actor !== $hostId) throw new \RuntimeException('Solo el anfitrión puede invitar.', 403);
                $guest = (int) ($body['guest_id'] ?? 0);
                if ($guest <= 0 || $guest === $hostId) throw new \RuntimeException('Invitado inválido.', 400);
                if ($state) throw new \RuntimeException('Cierra la invitación actual antes de invitar a otra persona.', 409);
                $state = ['id' => bin2hex(random_bytes(16)), 'host_id' => $hostId, 'guest_id' => $guest,
                    'status' => 'invited', 'expires' => $now + 60, 'host_seen' => $now,
                    'guest_seen' => $now, 'seq' => 0, 'signals' => []];
            } elseif ($action !== 'sync') {
                if (!$state || !hash_equals($state['id'], (string) ($body['session'] ?? ''))) throw new \RuntimeException('La invitación terminó.', 409);
                if ($actor !== $hostId && $actor !== $state['guest_id']) throw new \RuntimeException('No participas en este live compartido.', 403);
                if ($action === 'accept') {
                    if ($actor !== $state['guest_id']) throw new \RuntimeException('Solo el invitado puede aceptar.', 403);
                    if ($state['status'] !== 'invited') throw new \RuntimeException('La invitación ya fue aceptada.', 409);
                    $state['status'] = 'accepted';
                    $state['guest_seen'] = $state['host_seen'] = $now;
                } elseif ($action === 'leave') {
                    $state = null;
                } elseif ($action === 'signal') {
                    if ($state['status'] !== 'accepted') throw new \RuntimeException('El invitado aún no aceptó.', 409);
                    $signal = $body['signal'] ?? [];
                    $type = $signal['type'] ?? '';
                    if (!in_array($type, ['offer', 'answer', 'candidate'], true)) throw new \RuntimeException('Señal inválida.', 400);
                    if (($type === 'offer' && $actor !== $hostId) || ($type === 'answer' && $actor !== $state['guest_id'])) throw new \RuntimeException('Señal no autorizada.', 403);
                    if (strlen(json_encode($signal)) > 65536) throw new \RuntimeException('Señal demasiado grande.', 400);
                    if (count($state['signals']) >= 150) throw new \RuntimeException('Límite de negociación alcanzado. Reconecta al invitado.', 429);
                    $state['signals'][] = ['id' => ++$state['seq'], 'to' => $actor === $hostId ? $state['guest_id'] : $hostId, 'signal' => $signal];
                }
            }
            if (!$state) { $redis->del($key); return ['session' => null]; }
            $participant = $actor === $hostId || $actor === $state['guest_id'];
            if ($participant) {
                $state[$actor === $hostId ? 'host_seen' : 'guest_seen'] = $now;
                if ($state['status'] === 'accepted') $state['expires'] = $now + 90;
            }
            $redis->setex($key, 95, json_encode($state));
            if (!$participant) return ['session' => null];
            $since = max(0, (int) ($body['since'] ?? 0));
            $signals = array_values(array_filter($state['signals'], static fn($item) => $item['to'] === $actor && $item['id'] > $since));
            unset($state['signals']);
            return ['session' => $state, 'signals' => $signals];
        } finally {
            if ($locked) $redis->eval("if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end", [$lock, $owner], 1);
            $redis->close();
        }
    }
}
