<?php

namespace App\Controllers;

use App\Models\GroupPostsModel;
use App\Models\GroupsModel;
use App\Models\GroupLiveGiftModel;
use App\Models\GroupLiveRecordingModel;
use App\Models\GroupLiveChatModel;
use App\Models\WalletModel;
use App\Models\ReceiptModel;
use App\Models\UsersModel;
use App\Models\FamilyModel;
use EasyProjects\SimpleRouter\Router;

class LiveController
{
    private const RTMP_BASE = 'rtmp://live.tuanichat.com/live';
    private const HLS_BASE  = 'https://live.tuanichat.com/hls';
    private const WHEP_BASE = 'https://live.tuanichat.com/rtc/v1/whep/';

    private const MAX_RECORDING_SECONDS = 600; // 10 minutos
    private const MAX_RECORDING_BYTES = 190 * 1024 * 1024; // 190MB (margen bajo el límite del servidor de 200MB)
    private const ALLOWED_RECORDING_MIMES = ['video/webm', 'video/mp4'];

    public function __construct(
        private ?GroupPostsModel $groupPostsModel = null,
        private ?GroupsModel $groupsModel = null,
        private ?GroupLiveGiftModel $giftModel = null,
        private ?GroupLiveRecordingModel $recordingModel = null,
        private ?GroupLiveChatModel $chatModel = null,
        private ?UsersModel $usersModel = null,
        private ?FamilyModel $familyModel = null
    ) {
        $this->groupPostsModel = $groupPostsModel ?? new GroupPostsModel();
        $this->groupsModel = $groupsModel ?? new GroupsModel();
        $this->giftModel = $giftModel ?? new GroupLiveGiftModel();
        $this->recordingModel = $recordingModel ?? new GroupLiveRecordingModel();
        $this->chatModel = $chatModel ?? new GroupLiveChatModel();
        $this->usersModel = $usersModel ?? new UsersModel();
        $this->familyModel = $familyModel ?? new FamilyModel();
    }

    public function startLive()
    {
        $groupId = (int) (Router::$request->params->idGroup ?? 0);
        $userId  = (int) (Router::$request->user->id ?? 0);

        $body       = Router::$request->body;
        $visibility = $body->visibility ?? 'public';
        $giftAmount = $body->gift_amount ?? null;
        $isAdultContent = (bool) ($body->is_adult_content ?? false);
        $chatMode = $body->chat_mode ?? 'all';
        if (!in_array($chatMode, ['all', 'moderators', 'off'], true)) {
            $chatMode = 'all';
        }
        $invitedUserIds = is_array($body->invited_user_ids ?? null) ? $body->invited_user_ids : [];

        if ($groupId <= 0 || $userId <= 0) {
            Router::$response->status(400)->send(["message" => "Invalid request"]);
            return;
        }

        if (!in_array($visibility, ['public', 'private'], true)) {
            Router::$response->status(400)->send(["message" => "Invalid visibility"]);
            return;
        }

        if (!$this->groupsModel->isUserInGroup($groupId, $userId)) {
            Router::$response->status(403)->send(["message" => "No perteneces a este grupo"]);
            return;
        }

        $group = $this->groupsModel->getGroupById($groupId);
        if (!$group) {
            Router::$response->status(404)->send(["message" => "Grupo no encontrado"]);
            return;
        }
        if ((int) $group['created_by'] !== $userId) {
            Router::$response->status(403)->send(["message" => "Solo el creador del grupo puede iniciar un live"]);
            return;
        }

        // Cierra cualquier live huérfano del grupo (nunca se llamó a /live/stop
        // o el cliente reintentó /live/start sin cerrar el anterior), para que
        // el espectador nunca quede apuntando a un stream_key viejo sin manifiesto.
        $this->groupPostsModel->endOrphanedLivesByGroup($groupId);

        $result = $this->groupPostsModel->createLivePost($groupId, $userId, $visibility, $giftAmount, $isAdultContent, $chatMode);

        if (!$result) {
            Router::$response->status(500)->send(["message" => "Error creando el live"]);
            return;
        }

        if ($visibility === 'private' && !empty($invitedUserIds)) {
            $this->groupPostsModel->addInvitedViewers((int) $result['post_id'], $invitedUserIds);
        }

        Router::$response->status(201)->send([
            "message"      => "Live creado, listo para transmitir",
            "post_id"      => $result['post_id'],
            "rtmp_url"     => self::RTMP_BASE,
            "stream_key"   => $result['stream_key'],
            "playback_url" => self::HLS_BASE . '/' . $result['stream_key'] . '.m3u8',
            "whep_url"     => self::WHEP_BASE . '?app=live&stream=' . $result['stream_key'],
        ]);
    }

    public function getCurrentLive()
    {
        $groupId = (int) (Router::$request->params->idGroup ?? 0);
        $userId  = (int) (Router::$request->user->id ?? 0);

        if ($groupId <= 0 || $userId <= 0) {
            Router::$response->status(400)->send(["message" => "Invalid request"]);
            return;
        }

        if (!$this->groupsModel->isUserInGroup($groupId, $userId)) {
            Router::$response->status(403)->send(["message" => "No perteneces a este grupo"]);
            return;
        }

        $post = $this->groupPostsModel->getActiveLiveByGroup($groupId);

        if (!$post) {
            Router::$response->status(200)->send(["data" => null, "message" => "No hay live activo"]);
            return;
        }

        if ((string) $post['visibility'] === 'private' && (int) $post['user_id'] !== $userId && !$this->groupPostsModel->isInvitedViewer((int) $post['id'], $userId)) {
            Router::$response->status(403)->send(["message" => "Este live es privado", "private_live" => true]);
            return;
        }

        Router::$response->status(200)->send([
            "data" => [
                "post_id"      => $post['id'],
                "user_id"      => $post['user_id'],
                "playback_url" => self::HLS_BASE . '/' . $post['stream_key'] . '.m3u8',
                "whep_url"     => self::WHEP_BASE . '?app=live&stream=' . $post['stream_key'],
                "started_at"   => $post['started_at'],
                "is_adult_content" => (bool) $post['is_adult_content'],
                "visibility"   => $post['visibility'],
                "chat_mode"    => $post['chat_mode'],
            ],
            "message" => "Live activo"
        ]);
    }

    public function onPublish()
    {
        if (!$this->isInternalRequest()) {
            Router::$response->status(403)->send(["message" => "Forbidden"]);
            return;
        }

        $streamKey = $_POST['name'] ?? (Router::$request->body->name ?? null);

        if (!$streamKey) {
            Router::$response->status(400)->send(["message" => "Missing stream key"]);
            return;
        }

        $post = $this->groupPostsModel->getPostByStreamKey($streamKey);

        if (!$post || $post['type'] !== 'live') {
            Router::$response->status(403)->send(["message" => "Invalid stream key"]);
            return;
        }

        if ($post['status'] === 'ended') {
            Router::$response->status(403)->send(["message" => "This live has already ended"]);
            return;
        }

        $this->groupPostsModel->markLiveStarted($streamKey);

        Router::$response->status(200)->send(["message" => "OK"]);
    }

    public function onPublishDone()
    {
        if (!$this->isInternalRequest()) {
            Router::$response->status(403)->send(["message" => "Forbidden"]);
            return;
        }

        $streamKey = $_POST['name'] ?? (Router::$request->body->name ?? null);

        if (!$streamKey) {
            Router::$response->status(400)->send(["message" => "Missing stream key"]);
            return;
        }

        $post = $this->groupPostsModel->getPostByStreamKey($streamKey);

        $this->groupPostsModel->markLiveEnded($streamKey);

        if ($post && isset($post['id'])) {
            try {
                $postId = (int) $post['id'];
                $redis = new \Redis();
                $redis->connect('127.0.0.1', 6379, 1.0);
                $redis->publish('tuani:ws:broadcast', json_encode([
                    'origin' => 'php-fpm-live-status',
                    'kind' => 'live',
                    'live_id' => $postId,
                    'payload' => [
                        'type' => 'live_status',
                        'post_id' => $postId,
                        'status' => 'ended',
                        'ended_at' => date('c'),
                    ],
                ]));
                $redis->close();
            } catch (\Throwable $e) {
                error_log('onPublishDone: fallo el publish a Redis: ' . $e->getMessage());
            }
        }

        Router::$response->status(200)->send(["message" => "OK"]);
    }

    public function heartbeat()
    {
        $postId = (int) (Router::$request->params->postId ?? 0);
        $userId = (int) (Router::$request->user->id ?? 0);

        if ($postId <= 0 || $userId <= 0) {
            Router::$response->status(400)->send(["message" => "Invalid request"]);
            return;
        }

        $post = $this->groupPostsModel->getPostById($postId);
        if ($post) {
            if ($this->groupPostsModel->isViewerKicked($postId, $userId)) {
                Router::$response->status(403)->send(["message" => "Fuiste expulsado de este live", "kicked" => true]);
                return;
            }
            if ($this->groupPostsModel->isViewerBlocked((int)$post["group_id"], $userId, $postId)) {
                Router::$response->status(403)->send(["message" => "No tenes acceso a este live", "blocked" => true]);
                return;
            }
            if ((string) $post['visibility'] === 'private' && (int) $post['user_id'] !== $userId && !$this->groupPostsModel->isInvitedViewer($postId, $userId)) {
                Router::$response->status(403)->send(["message" => "Este live es privado", "private_live" => true]);
                return;
            }
            if (!empty($post['is_adult_content'])) {
                if ($this->familyModel->isBlockedFromAdultContent($userId)) {
                    Router::$response->status(403)->send([
                        "message" => "Tu cuenta tiene el contenido +18 bloqueado por control parental",
                        "parental_block" => true,
                    ]);
                    return;
                }
                if ($this->usersModel->getAgeVerificationStatus($userId) !== 'approved') {
                    Router::$response->status(403)->send([
                        "message" => "Este live es +18. Necesitas verificar tu edad para poder verlo",
                        "needs_age_verification" => true,
                        "age_verification_status" => $this->usersModel->getAgeVerificationStatus($userId),
                    ]);
                    return;
                }
            }
        }

        $activeViewers = $this->groupPostsModel->registerViewerHeartbeat($postId, $userId);
        $isModerator = $post ? $this->groupPostsModel->isViewerModerator($postId, $userId) : false;

        Router::$response->status(200)->send([
            "message" => "OK",
            "active_viewers" => $activeViewers,
            "is_moderator" => $isModerator
        ]);
    }

    public function leaveLive()
    {
        $postId = (int) (Router::$request->params->postId ?? 0);
        $userId = (int) (Router::$request->user->id ?? 0);

        if ($postId <= 0 || $userId <= 0) {
            Router::$response->status(400)->send(["message" => "Invalid request"]);
            return;
        }

        $this->groupPostsModel->removeViewer($postId, $userId);

        Router::$response->status(200)->send(["message" => "OK"]);
    }

    public function getSummary()
    {
        $postId = (int) (Router::$request->params->postId ?? 0);

        if ($postId <= 0) {
            Router::$response->status(400)->send(["message" => "Invalid request"]);
            return;
        }

        $summary = $this->groupPostsModel->getLiveSummary($postId);

        if (!$summary) {
            Router::$response->status(404)->send(["message" => "Live not found"]);
            return;
        }

        $durationSeconds = 0;
        if (!empty($summary["started_at"]) && !empty($summary["ended_at"])) {
            $durationSeconds = strtotime($summary["ended_at"]) - strtotime($summary["started_at"]);
        }

        Router::$response->status(200)->send([
            "data" => [
                "post_id" => $summary["id"],
                "total_gifts" => (float) $summary["total_gifts"],
                "unique_donors" => (int) $summary["unique_donors"],
                "peak_viewers" => (int) $summary["peak_viewers"],
                "started_at" => $summary["started_at"],
                "ended_at" => $summary["ended_at"],
                "duration_seconds" => $durationSeconds
            ],
            "message" => "Summary retrieved"
        ]);
    }

    private function canModerate(array $post, int $userId): bool
    {
        if ((int) $post['user_id'] === $userId) {
            return true;
        }
        return $this->groupPostsModel->isViewerModerator((int) $post['id'], $userId);
    }

    public function getViewers()
    {
        $postId = (int) (Router::$request->params->postId ?? 0);
        $userId = (int) (Router::$request->user->id ?? 0);

        $post = $this->groupPostsModel->getPostById($postId);
        if (!$post) {
            Router::$response->status(404)->send(["message" => "Live not found"]);
            return;
        }

        if (!$this->canModerate($post, $userId)) {
            Router::$response->status(403)->send(["message" => "Solo el anfitrion o un moderador puede ver esta lista"]);
            return;
        }

        $viewers = $this->groupPostsModel->getActiveViewersList($postId);

        Router::$response->status(200)->send(["data" => $viewers, "message" => "OK"]);
    }

    public function kickViewer()
    {
        $postId = (int) (Router::$request->params->postId ?? 0);
        $targetUserId = (int) (Router::$request->params->userId ?? 0);
        $requesterId = (int) (Router::$request->user->id ?? 0);

        $post = $this->groupPostsModel->getPostById($postId);
        if (!$post) {
            Router::$response->status(404)->send(["message" => "Live not found"]);
            return;
        }

        if (!$this->canModerate($post, $requesterId)) {
            Router::$response->status(403)->send(["message" => "Solo el anfitrion o un moderador puede expulsar participantes"]);
            return;
        }

        $this->groupPostsModel->kickViewer($postId, $targetUserId);

        try {
            $redis = new \Redis();
            $redis->connect('127.0.0.1', 6379, 1.0);
            $redis->publish('tuani:ws:broadcast', json_encode([
                'origin' => 'php-fpm-live-kicked',
                'kind' => 'live',
                'live_id' => $postId,
                'payload' => [
                    'type' => 'live_kicked',
                    'post_id' => $postId,
                    'target_user_id' => $targetUserId,
                ],
            ]));
            $redis->close();
        } catch (\Throwable $e) {
            error_log('php-fpm-live-kicked: fallo el publish a Redis: ' . $e->getMessage());
        }

        Router::$response->status(200)->send(["message" => "Usuario expulsado"]);
    }

    public function blockViewer()
    {
        $postId = (int) (Router::$request->params->postId ?? 0);
        $targetUserId = (int) (Router::$request->params->userId ?? 0);
        $requesterId = (int) (Router::$request->user->id ?? 0);
        $scope = Router::$request->body->scope ?? 'live';

        if (!in_array($scope, ['live', 'group'], true)) {
            Router::$response->status(400)->send(["message" => "Invalid scope"]);
            return;
        }

        $post = $this->groupPostsModel->getPostById($postId);
        if (!$post) {
            Router::$response->status(404)->send(["message" => "Live not found"]);
            return;
        }

        if (!$this->canModerate($post, $requesterId)) {
            Router::$response->status(403)->send(["message" => "Solo el anfitrion o un moderador puede bloquear participantes"]);
            return;
        }

        $this->groupPostsModel->blockViewer((int)$post["group_id"], $targetUserId, $requesterId, $scope, $postId);

        try {
            $redis = new \Redis();
            $redis->connect('127.0.0.1', 6379, 1.0);
            $redis->publish('tuani:ws:broadcast', json_encode([
                'origin' => 'php-fpm-live-blocked',
                'kind' => 'live',
                'live_id' => $postId,
                'payload' => [
                    'type' => 'live_blocked',
                    'post_id' => $postId,
                    'target_user_id' => $targetUserId,
                    'scope' => $scope,
                ],
            ]));
            $redis->close();
        } catch (\Throwable $e) {
            error_log('php-fpm-live-blocked: fallo el publish a Redis: ' . $e->getMessage());
        }

        Router::$response->status(200)->send(["message" => "Usuario bloqueado"]);
    }

    public function muteViewer()
    {
        $postId = (int) (Router::$request->params->postId ?? 0);
        $targetUserId = (int) (Router::$request->params->userId ?? 0);
        $requesterId = (int) (Router::$request->user->id ?? 0);
        $muted = (bool) (Router::$request->body->muted ?? true);

        $post = $this->groupPostsModel->getPostById($postId);
        if (!$post) {
            Router::$response->status(404)->send(["message" => "Live not found"]);
            return;
        }

        if (!$this->canModerate($post, $requesterId)) {
            Router::$response->status(403)->send(["message" => "Solo el anfitrion o un moderador puede silenciar participantes"]);
            return;
        }

        $this->groupPostsModel->muteViewer($postId, $targetUserId, $muted);

        try {
            $redis = new \Redis();
            $redis->connect('127.0.0.1', 6379, 1.0);
            $redis->publish('tuani:ws:broadcast', json_encode([
                'origin' => 'php-fpm-live-muted',
                'kind' => 'live',
                'live_id' => $postId,
                'payload' => [
                    'type' => 'live_muted',
                    'post_id' => $postId,
                    'target_user_id' => $targetUserId,
                    'muted' => $muted,
                ],
            ]));
            $redis->close();
        } catch (\Throwable $e) {
            error_log('php-fpm-live-muted: fallo el publish a Redis: ' . $e->getMessage());
        }

        Router::$response->status(200)->send([
            "message" => $muted ? "Usuario silenciado" : "Silencio removido",
            "muted" => $muted
        ]);
    }

    public function setModerator()
    {
        $postId = (int) (Router::$request->params->postId ?? 0);
        $targetUserId = (int) (Router::$request->params->userId ?? 0);
        $requesterId = (int) (Router::$request->user->id ?? 0);
        $isModerator = (bool) (Router::$request->body->is_moderator ?? true);

        $post = $this->groupPostsModel->getPostById($postId);
        if (!$post) {
            Router::$response->status(404)->send(["message" => "Live not found"]);
            return;
        }

        if ((int)$post["user_id"] !== $requesterId) {
            Router::$response->status(403)->send(["message" => "Solo el anfitrion puede asignar moderadores"]);
            return;
        }

        $this->groupPostsModel->setViewerModerator($postId, $targetUserId, $isModerator);

        try {
            $redis = new \Redis();
            $redis->connect('127.0.0.1', 6379, 1.0);
            $redis->publish('tuani:ws:broadcast', json_encode([
                'origin' => 'php-fpm-live-moderator',
                'kind' => 'live',
                'live_id' => $postId,
                'payload' => [
                    'type' => 'live_moderator',
                    'post_id' => $postId,
                    'target_user_id' => $targetUserId,
                    'is_moderator' => $isModerator,
                ],
            ]));
            $redis->close();
        } catch (\Throwable $e) {
            error_log('php-fpm-live-moderator: fallo el publish a Redis: ' . $e->getMessage());
        }

        Router::$response->status(200)->send([
            "message" => $isModerator ? "Moderador asignado" : "Moderador removido",
            "is_moderator" => $isModerator
        ]);
    }

    private const GIFT_TIERS = [1, 5, 10, 20, 30, 50, 100, 200, 300, 500];
    private const PIN_TIERS = [100 => 10, 200 => 20, 300 => 30, 400 => 40, 500 => 50, 600 => 60];

    public function sendGift()
    {
        $postId = (int) (Router::$request->params->postId ?? 0);
        $userId = (int) (Router::$request->user->id ?? 0);
        $body = Router::$request->body;
        $amount = (float) ($body->amount ?? 0);

        if ($postId <= 0 || $userId <= 0) {
            Router::$response->status(400)->send(["message" => "Invalid request"]);
            return;
        }

        if (!in_array((int)$amount, self::GIFT_TIERS, true)) {
            Router::$response->status(400)->send(["message" => "Monto invalido"]);
            return;
        }

        $post = $this->groupPostsModel->getPostById($postId);
        if (!$post || $post['status'] !== 'live') {
            Router::$response->status(404)->send(["message" => "Live no encontrado o no activo"]);
            return;
        }

        // Cobro directo del wallet, ya no via Square
        $walletModel = new WalletModel();
        $walletResult = $walletModel->debitForPurchase($userId, $amount, 'live_gift_post_' . $postId);
        if (!$walletResult['success']) {
            Router::$response->status(402)->send(["message" => $walletResult['message'], "insufficientBalance" => true]);
            return;
        }

        $giftId = $this->giftModel->create($postId, $userId, $amount, '', '', 'wallet_' . $userId . '_' . time());
        $this->giftModel->markAsCompleted($giftId);

        $receiptModel = new ReceiptModel();
        $receiptModel->createAndNotify(
            $userId,
            'live_gift',
            $giftId,
            $amount,
            'wallet',
            'Regalo en live #' . $postId
        );

        Router::$response->status(201)->send([
            "message" => "Regalo enviado con tu wallet",
            "success" => true,
            "gift_id" => $giftId,
            "amount" => $amount,
            "newBalance" => $walletResult['new_balance'],
        ]);
    }

    public function getGiftStatus()
    {
        $giftId = (int) (Router::$request->params->giftId ?? 0);

        if ($giftId <= 0) {
            Router::$response->status(400)->send(["message" => "Invalid request"]);
            return;
        }

        $gift = $this->giftModel->getById($giftId);
        if (!$gift) {
            Router::$response->status(404)->send(["message" => "Gift not found"]);
            return;
        }

        Router::$response->status(200)->send([
            "completed" => $gift["estado"] === "completado"
        ]);
    }

    public function getLivePoints()
    {
        $postId = (int) (Router::$request->params->postId ?? 0);
        $userId = (int) (Router::$request->user->id ?? 0);

        if ($postId <= 0 || $userId <= 0) {
            Router::$response->status(400)->send(["message" => "Invalid request"]);
            return;
        }

        $livePoints = $this->giftModel->getLivePoints($postId);
        $totalPoints = $this->giftModel->getUserTotalPoints($userId);

        Router::$response->status(200)->send([
            "data" => [
                "live_points" => $livePoints,
                "total_points" => $totalPoints
            ]
        ]);
    }

    private function createGiftPaymentLink(int $postId, float $amount, ?string $itemName = null): array
    {
        $appEnv = isset($_ENV['APP_ENV']) ? strtolower((string) $_ENV['APP_ENV']) : '';
        $isProd = ($appEnv === 'production');

        if ($isProd) {
            $accessToken = trim((string) ($_ENV['SQUARE_ACCESS_TOKEN_PROD'] ?? $_ENV['SQUARE_ACCESS_TOKEN'] ?? ''));
            $locationId = (string) ($_ENV['SQUARE_LOCATION_ID_PROD'] ?? $_ENV['SQUARE_LOCATION_ID'] ?? '');
        } else {
            $accessToken = trim((string) ($_ENV['SQUARE_ACCESS_TOKEN_SANDBOX'] ?? $_ENV['SQUARE_ACCESS_TOKEN'] ?? ''));
            $locationId = (string) ($_ENV['SQUARE_LOCATION_ID_SANDBOX'] ?? $_ENV['SQUARE_LOCATION_ID'] ?? '');
        }
        $locationId = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($locationId));

        $sandboxEnv = $_ENV['SQUARE_SANDBOX'] ?? '';
        $sandbox = ($sandboxEnv === 'true' || $sandboxEnv === '1') ? true : !$isProd;
        $squareBaseUrl = $sandbox ? 'https://connect.squareupsandbox.com' : 'https://connect.squareup.com';

        if (empty($accessToken) || empty($locationId)) {
            return [
                'url' => null,
                'payment_link_id' => null,
                'idempotency_key' => null,
                'error' => empty($accessToken) ? 'SQUARE_ACCESS_TOKEN no configurado' : 'SQUARE_LOCATION_ID no configurado'
            ];
        }

        $amountCents = (int) round($amount * 100);
        $idempotencyKey = uniqid('gift_', true);
        $postData = [
            "idempotency_key" => $idempotencyKey,
            "quick_pay" => [
                "name" => $itemName ?? "Regalo en live #{$postId}",
                "price_money" => [
                    "amount" => $amountCents,
                    "currency" => "USD"
                ],
                "location_id" => $locationId
            ]
        ];

        $ch = curl_init($squareBaseUrl . "/v2/online-checkout/payment-links");
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Bearer $accessToken",
            "Square-Version: 2024-11-20"
        ]);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $result = is_string($response) ? json_decode($response, true) : [];
        $paymentLinkUrl = $result['payment_link']['url'] ?? null;
        $squarePaymentLinkId = $result['payment_link']['id'] ?? null;

        $error = null;
        if ($curlError) {
            $error = $curlError;
        } elseif (($httpcode !== 200 && $httpcode !== 201) || !$paymentLinkUrl) {
            if (!empty($result['errors']) && is_array($result['errors'])) {
                $first = $result['errors'][0] ?? [];
                $error = ($first['code'] ?? '') . ': ' . ($first['detail'] ?? $first['message'] ?? json_encode($first));
            } else {
                $error = 'Square no devolvio payment link';
            }
        }

        return [
            'url' => $paymentLinkUrl,
            'payment_link_id' => $squarePaymentLinkId,
            'idempotency_key' => $idempotencyKey,
            'error' => $error
        ];
    }

    public function getNewGifts()
    {
        $postId = (int) (Router::$request->params->postId ?? 0);
        $userId = (int) (Router::$request->user->id ?? 0);
        $sinceId = (int) ($_GET['since_id'] ?? 0);

        if ($postId <= 0 || $userId <= 0) {
            Router::$response->status(400)->send(["message" => "Invalid request"]);
            return;
        }

        $post = $this->groupPostsModel->getPostById($postId);
        if (!$post) {
            Router::$response->status(404)->send(["message" => "Live not found"]);
            return;
        }

        if ((int)$post["user_id"] !== $userId) {
            Router::$response->status(403)->send(["message" => "Solo el anfitrion puede ver esto"]);
            return;
        }

        $gifts = $this->giftModel->getCompletedGiftsSince($postId, $sinceId);

        Router::$response->status(200)->send(["data" => $gifts]);
    }

    public function uploadRecording()
    {
        $postId = (int) (Router::$request->params->postId ?? 0);
        $userId = (int) (Router::$request->user->id ?? 0);
        if ($postId <= 0 || $userId <= 0) {
            Router::$response->status(400)->send(["message" => "Invalid request"]);
            return;
        }
        $post = $this->groupPostsModel->getPostById($postId);
        if (!$post) {
            Router::$response->status(404)->send(["message" => "Live not found"]);
            return;
        }
        if ((int) $post["user_id"] !== $userId) {
            Router::$response->status(403)->send(["message" => "Solo el anfitrion puede subir la grabacion"]);
            return;
        }
        if (empty($_FILES['recording']) || $_FILES['recording']['error'] !== UPLOAD_ERR_OK) {
            Router::$response->status(400)->send(["message" => "Archivo de grabacion invalido"]);
            return;
        }
        $file = $_FILES['recording'];
        if ($file['size'] > self::MAX_RECORDING_BYTES) {
            Router::$response->status(413)->send(["message" => "La grabacion excede el tamano maximo permitido"]);
            return;
        }
        $mime = mime_content_type($file['tmp_name']);
        if (!in_array($mime, self::ALLOWED_RECORDING_MIMES, true)) {
            Router::$response->status(415)->send(["message" => "Formato de grabacion no soportado"]);
            return;
        }
        $durationSeconds = (int) ($_POST['duration_seconds'] ?? 0);
        if ($durationSeconds > self::MAX_RECORDING_SECONDS) {
            $durationSeconds = self::MAX_RECORDING_SECONDS;
        }
        $groupId = (int) $post['group_id'];
        $uploadDir = $this->getRecordingUploadDir($groupId);
        $ext = ($mime === 'video/mp4') ? 'mp4' : 'webm';
        $filename = 'live_' . $postId . '_' . uniqid() . '.' . $ext;
        $target = $uploadDir . $filename;
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            Router::$response->status(500)->send(["message" => "No se pudo guardar la grabacion"]);
            return;
        }
        $relativePath = '/uploads/live_recordings/' . $groupId . '/' . $filename;
        $recordingId = $this->recordingModel->create(
            $postId,
            $groupId,
            $userId,
            $relativePath,
            $durationSeconds,
            (int) $file['size']
        );
        Router::$response->status(201)->send([
            "data" => [
                "id" => $recordingId,
                "file_path" => $relativePath,
            ],
            "message" => "Grabacion guardada"
        ]);
    }

    public function getRecordings()
    {
        $groupId = (int) (Router::$request->params->idGroup ?? 0);
        if ($groupId <= 0) {
            Router::$response->status(400)->send(["message" => "Invalid request"]);
            return;
        }
        $recordings = $this->recordingModel->getByGroup($groupId);
        Router::$response->status(200)->send(["data" => $recordings]);
    }

    private function getRecordingUploadDir(int $groupId): string
    {
        $base = __DIR__ . '/../../uploads/live_recordings/' . $groupId . '/';
        if (!is_dir($base)) {
            mkdir($base, 0755, true);
        }
        $resolved = realpath($base);
        return ($resolved ?: $base) . DIRECTORY_SEPARATOR;
    }

    public function sendChatMessage()
    {
        $postId = (int) (Router::$request->params->postId ?? 0);
        $userId = (int) (Router::$request->user->id ?? 0);
        $body = Router::$request->body;
        $message = trim((string) ($body->message ?? ''));
        if ($postId <= 0 || $userId <= 0) {
            Router::$response->status(400)->send(["message" => "Invalid request"]);
            return;
        }
        if ($message === '' || mb_strlen($message) > 500) {
            Router::$response->status(400)->send(["message" => "Mensaje invalido"]);
            return;
        }
        $post = $this->groupPostsModel->getPostById($postId);
        if (!$post || $post['status'] !== 'live') {
            Router::$response->status(404)->send(["message" => "Live no encontrado o no activo"]);
            return;
        }
        if ($this->groupPostsModel->isViewerMuted($postId, $userId)) {
            Router::$response->status(403)->send(["message" => "Estas silenciado en este live"]);
            return;
        }
        $chatMode = $post['chat_mode'] ?? 'all';
        if ($chatMode === 'off' && !$this->canModerate($post, $userId)) {
            Router::$response->status(403)->send(["message" => "El chat esta desactivado en este live"]);
            return;
        }
        if ($chatMode === 'moderators' && !$this->canModerate($post, $userId)) {
            Router::$response->status(403)->send(["message" => "Solo los moderadores pueden comentar en este live"]);
            return;
        }
        $messageId = $this->chatModel->createFreeMessage($postId, $userId, $message);

        try {
            $senderName = Router::$request->user->name ?? null;
            $redis = new \Redis();
            $redis->connect('127.0.0.1', 6379, 1.0);
            $redis->publish('tuani:ws:broadcast', json_encode([
                'origin' => 'php-fpm-live-chat',
                'kind' => 'live',
                'live_id' => $postId,
                'payload' => [
                    'type' => 'live_chat_message',
                    'post_id' => $postId,
                    'id' => $messageId,
                    'sender_user_id' => $userId,
                    'sender_name' => $senderName,
                    'message' => $message,
                    'created_at' => date('c'),
                ],
            ]));
            $redis->close();
        } catch (\Throwable $e) {
            error_log('sendChatMessage (live): fallo el publish a Redis: ' . $e->getMessage());
        }

        Router::$response->status(201)->send([
            "data" => ["id" => $messageId],
            "message" => "Mensaje enviado"
        ]);
    }

    public function sendPinnedMessage()
    {
        $postId = (int) (Router::$request->params->postId ?? 0);
        $userId = (int) (Router::$request->user->id ?? 0);
        $body = Router::$request->body;
        $message = trim((string) ($body->message ?? ''));
        $amount = (float) ($body->amount ?? 0);
        if ($postId <= 0 || $userId <= 0) {
            Router::$response->status(400)->send(["message" => "Invalid request"]);
            return;
        }
        if ($message === '' || mb_strlen($message) > 500) {
            Router::$response->status(400)->send(["message" => "Mensaje invalido"]);
            return;
        }
        $amountKey = (int) $amount;
        if (!array_key_exists($amountKey, self::PIN_TIERS)) {
            Router::$response->status(400)->send(["message" => "Monto invalido"]);
            return;
        }
        $minutes = self::PIN_TIERS[$amountKey];
        $post = $this->groupPostsModel->getPostById($postId);
        if (!$post || $post['status'] !== 'live') {
            Router::$response->status(404)->send(["message" => "Live no encontrado o no activo"]);
            return;
        }

        // Cobro directo del wallet, ya no via Square
        $walletModel = new WalletModel();
        $walletResult = $walletModel->debitForPurchase($userId, $amount, 'live_pin_post_' . $postId);
        if (!$walletResult['success']) {
            Router::$response->status(402)->send(["message" => $walletResult['message'], "insufficientBalance" => true]);
            return;
        }

        $pinId = $this->chatModel->createPinRequest($postId, $userId, $message, $amount, $minutes, '', '', 'wallet_' . $userId . '_' . time());
        $this->chatModel->markPinCompleted($pinId);

        $receiptModel = new ReceiptModel();
        $receiptModel->createAndNotify(
            $userId,
            'live_pin',
            $pinId,
            $amount,
            'wallet',
            'Mensaje anclado en live #' . $postId . " ({$minutes} min)"
        );

        Router::$response->status(201)->send([
            "message" => "Mensaje anclado con tu wallet",
            "success" => true,
            "pin_id" => $pinId,
            "amount" => $amount,
            "minutes" => $minutes,
            "newBalance" => $walletResult['new_balance'],
        ]);
    }

    public function getChatMessages()
    {
        $postId = (int) (Router::$request->params->postId ?? 0);
        $sinceId = (int) ($_GET['since_id'] ?? 0);
        if ($postId <= 0) {
            Router::$response->status(400)->send(["message" => "Invalid request"]);
            return;
        }
        $messages = $this->chatModel->getMessagesSince($postId, $sinceId);
        Router::$response->status(200)->send(["data" => $messages]);
    }

    public function getPinnedMessages()
    {
        $postId = (int) (Router::$request->params->postId ?? 0);
        if ($postId <= 0) {
            Router::$response->status(400)->send(["message" => "Invalid request"]);
            return;
        }
        $pinned = $this->chatModel->getActivePinned($postId);
        Router::$response->status(200)->send(["data" => $pinned]);
    }

    private function isInternalRequest(): bool
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return in_array($ip, ['127.0.0.1', '::1'], true);
    }
}
