<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
if (($_ENV['PUSH_DELIVERY_ENABLED'] ?? getenv('PUSH_DELIVERY_ENABLED')) !== 'true') {
    fwrite(STDERR, "Push deshabilitado. Configurar explícitamente el entorno y su proyecto Firebase antes de habilitar.\n");
    exit(2);
}
$db = App\Configs\Database::getInstance()->getConnection();
$queue = new App\Services\PushOutbox($db);
$tokens = new App\Models\DeviceTokenModel();
$once = in_array('--once', $argv, true);
do {
    try {
        $queue->expire(time());
        $job = $queue->claim(time());
        if (!$job) { if ($once) break; usleep(500000); continue; }
        $token = null;
        // Recheck ownership at delivery: a device may have logged out or changed accounts.
        foreach ($tokens->getActiveTokensForUser((int) $job['user_id']) as $device) {
            if (hash_equals($job['token_hash'], hash('sha256', $device['fcm_token']))) $token = $device['fcm_token'];
        }
        $sent = false;
        $unregistered = false;
        $data = json_decode($job['payload'], true, 512, JSON_THROW_ON_ERROR);
        if (($data['type'] ?? '') === 'incoming_call') {
            $calls = new App\Services\CallRegistry($db);
            $call = $calls[$data['session_id']] ?? null;
            if (!$call || (int)$call['callee_id'] !== (int)$job['user_id'] || !App\Services\CallRegistry::isRinging($call, time())) {
                $queue->finish($job, false, true, time());
                continue;
            }
        }
        if (($data['type'] ?? '') === 'ride_update' && !App\Services\RidePush::isCurrent($db,$data,(int)$job['user_id'])) {
            $queue->finish($job, false, true, time());
            continue;
        }
        if ($token !== null && (int) $job['expires_at'] > time()) {
            $sent = App\Services\FcmService::sendDataMessage($token, $data);
            $unregistered = App\Services\FcmService::lastTokenWasUnregistered();
            if ($unregistered) $tokens->deactivateToken($token);
        }
        $queue->finish($job, $sent, $token === null || $unregistered, time(), App\Services\FcmService::retryAfterSeconds());
    } catch (Throwable $e) {
        // Lease expiry recovers interrupted work. Avoid secrets/payloads in operational logs.
        fwrite(STDERR, "No se pudo procesar un trabajo push; se reintentará según su arrendamiento.\n");
        if ($once) exit(1);
        sleep(2);
    }
} while (!$once);
