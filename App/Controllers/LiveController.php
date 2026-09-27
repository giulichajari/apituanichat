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

        if (empty(Router::$request->user->is_verified)) {
            Router::$response->status(403)->send(["message" => "Tenés que tener tu cuenta verificada para poder acceder"]);
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

        if ($amount !== (float) (int) $amount || !in_array((int)$amount, self::GIFT_TIERS, true)) {
            Router::$response->status(400)->send(["message" => "Monto invalido"]);
            return;
        }

        try {
            $completed = (new \App\Services\WalletCheckout())->completed($userId, 'live-gift', (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''), ['post' => $postId, 'amount' => $amount, 'message' => '']);
            if ($completed !== null) { Router::$response->status(200)->send($completed); return; }
        } catch (\InvalidArgumentException $e) { Router::$response->status(409)->send(['message' => $e->getMessage()]); return; }
        $post = $this->groupPostsModel->getPostById($postId);
        if (!$post || $post['status'] !== 'live') {
            Router::$response->status(404)->send(["message" => "Live no encontrado o no activo"]);
            return;
        }

        if (!$this->requireLiveAccess($postId, $userId)) return;
        try {
            $key = (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
            $intent = ['post' => $postId, 'amount' => $amount, 'message' => $message ?? ''];
            $result = (new \App\Services\WalletCheckout())->run($userId, 'live-gift', $key, $intent, function () use ($postId, $userId, $amount, $post, $key) {
                $db = \App\Configs\Database::getInstance()->getConnection();
                $lock = $db->prepare('SELECT * FROM group_posts WHERE id = ? FOR UPDATE');
                $lock->execute([$postId]);$post = $lock->fetch(\PDO::FETCH_ASSOC);
                if (!$post || $post['status'] !== 'live') throw new \DomainException('El Live terminó; no se realizó el cobro');
                if (!$this->requireLiveAccess($postId, $userId, false)) throw new \DomainException('Acceso al Live revocado');
                $debit = (new WalletModel())->debitForPurchase($userId, $amount, 'live-gift_' . $postId);
                if (!$debit['success']) throw new \DomainException($debit['message']);
                $id = $this->giftModel->create($postId, $userId, $amount, '', '', $key);
                if (!$id || !$this->giftModel->markAsCompleted($id)) throw new \RuntimeException('No se pudo registrar el beneficio');
                (new \App\Services\CommerceSettlement())->hold('live-gift_' . $id, $debit['transaction_id'], (int) $post['user_id']);
                (new \App\Services\CommerceSettlement())->resolve('live-gift_' . $id, 'release', $userId, 'Entrega digital confirmada');
                return ['success' => true, 'gift_id' => $id, 'amount' => $amount, 'newBalance' => $debit['new_balance']];
            });
            if (empty($result['replayed'])) {
                try { (new ReceiptModel())->createAndNotify($userId, 'live_gift', $result['gift_id'], $amount, 'wallet', 'Live #' . $postId); }
                catch (\Throwable $e) { error_log('Live receipt pending'); }
            }
            Router::$response->status(201)->send($result);
        } catch (\Throwable $e) {
            error_log('Live checkout: ' . $e->getMessage());
            Router::$response->status(409)->send(['message' => 'No se completó el pago. Revisa el saldo y reintenta.']);
        }
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



    public function getNewGifts()
    {
        $postId = (int) (Router::$request->params->postId ?? 0);
        if (!$this->requireLiveAccess($postId, (int) (Router::$request->user->id ?? 0))) return;
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
        if ($amount !== (float) $amountKey || !array_key_exists($amountKey, self::PIN_TIERS)) {
            Router::$response->status(400)->send(["message" => "Monto invalido"]);
            return;
        }
        $minutes = self::PIN_TIERS[$amountKey];
        try {
            $completed = (new \App\Services\WalletCheckout())->completed($userId, 'live-pin', (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''), ['post' => $postId, 'amount' => $amount, 'message' => $message]);
            if ($completed !== null) { Router::$response->status(200)->send($completed); return; }
        } catch (\InvalidArgumentException $e) { Router::$response->status(409)->send(['message' => $e->getMessage()]); return; }
        $post = $this->groupPostsModel->getPostById($postId);
        if (!$post || $post['status'] !== 'live') {
            Router::$response->status(404)->send(["message" => "Live no encontrado o no activo"]);
            return;
        }

        if (!$this->requireLiveAccess($postId, $userId)) return;
        try {
            $key = (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
            $intent = ['post' => $postId, 'amount' => $amount, 'message' => $message ?? ''];
            $result = (new \App\Services\WalletCheckout())->run($userId, 'live-pin', $key, $intent, function () use ($postId, $userId, $amount, $post, $key, $message, $minutes) {
                $db = \App\Configs\Database::getInstance()->getConnection();
                $lock = $db->prepare('SELECT * FROM group_posts WHERE id = ? FOR UPDATE');
                $lock->execute([$postId]);$post = $lock->fetch(\PDO::FETCH_ASSOC);
                if (!$post || $post['status'] !== 'live') throw new \DomainException('El Live terminó; no se realizó el cobro');
                if (!$this->requireLiveAccess($postId, $userId, false)) throw new \DomainException('Acceso al Live revocado');
                $debit = (new WalletModel())->debitForPurchase($userId, $amount, 'live-pin_' . $postId);
                if (!$debit['success']) throw new \DomainException($debit['message']);
                $id = $this->chatModel->createPinRequest($postId, $userId, $message, $amount, $minutes, '', '', $key);
                if (!$id || !$this->chatModel->markPinCompleted($id)) throw new \RuntimeException('No se pudo registrar el beneficio');
                (new \App\Services\CommerceSettlement())->hold('live-pin_' . $id, $debit['transaction_id'], (int) $post['user_id']);
                (new \App\Services\CommerceSettlement())->resolve('live-pin_' . $id, 'release', $userId, 'Entrega digital confirmada');
                return ['success' => true, 'pin_id' => $id, 'minutes' => $minutes, 'amount' => $amount, 'newBalance' => $debit['new_balance']];
            });
            if (empty($result['replayed'])) {
                try { (new ReceiptModel())->createAndNotify($userId, 'live_pin', $result['pin_id'], $amount, 'wallet', 'Live #' . $postId); }
                catch (\Throwable $e) { error_log('Live receipt pending'); }
            }
            Router::$response->status(201)->send($result);
        } catch (\Throwable $e) {
            error_log('Live checkout: ' . $e->getMessage());
            Router::$response->status(409)->send(['message' => 'No se completó el pago. Revisa el saldo y reintenta.']);
        }
    }

    public function getChatMessages()
    {
        $postId = (int) (Router::$request->params->postId ?? 0);
        if (!$this->requireLiveAccess($postId, (int) (Router::$request->user->id ?? 0))) return;
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
        if (!$this->requireLiveAccess($postId, (int) (Router::$request->user->id ?? 0))) return;
        if ($postId <= 0) {
            Router::$response->status(400)->send(["message" => "Invalid request"]);
            return;
        }
        $pinned = $this->chatModel->getActivePinned($postId);
        Router::$response->status(200)->send(["data" => $pinned]);
    }

    public function liveGuest()
    {
        $postId = (int) (Router::$request->params->postId ?? 0);
        $actor = (int) (Router::$request->user->id ?? 0);
        if (!$this->requireCaptionAccess($postId, $actor)) return;
        $post = $this->groupPostsModel->getPostById($postId);
        if (($post['type'] ?? '') !== 'live' || ($post['status'] ?? '') === 'ended') {
            Router::$response->status(409)->send(['message' => 'Este live ya no está disponible.']); return;
        }
        $body = json_decode(json_encode(Router::$request->body ?? []), true);
        if (!is_array($body)) $body = [];
        $host = (int) $post['user_id'];
        if (($body['action'] ?? '') === 'invite') {
            $guest = (int) ($body['guest_id'] ?? 0);
            if ($actor !== $host || $guest <= 0 || $guest === $host
                || !$this->groupsModel->isUserInGroup((int) $post['group_id'], $guest)
                || !$this->requireLiveAccess($postId, $guest, false)) {
                Router::$response->status(403)->send(['message' => 'No se puede invitar a esa persona a este live.']); return;
            }
        }
        header('Cache-Control: no-store');
        try {
            $result = (new \App\Services\LiveGuestService())->exchange($postId, $host, $actor, $body);
            Router::$response->status(200)->send($result);
        } catch (\Throwable $e) {
            $code = (int) $e->getCode();
            $known = in_array($code, [400, 403, 409, 429], true);
            Router::$response->status($known ? $code : 503)->send(['message' => $known ? $e->getMessage() : 'Live compartido temporalmente no disponible.']);
        }
    }

    public function getCaptions()
    {
        $postId = (int) (Router::$request->params->postId ?? 0);
        if (!$this->requireCaptionAccess($postId, (int) (Router::$request->user->id ?? 0))) return;
        header('Cache-Control: no-store');
        if (($_GET['publisher'] ?? '') === '1') {
            $post = $this->groupPostsModel->getPostById($postId);
            if ((int) $post['user_id'] !== (int) (Router::$request->user->id ?? 0)) {
                Router::$response->status(403)->send(['message' => 'Solo el transmisor puede activar la traducción.']);
                return;
            }
            if (trim((string) ($_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?: '')) === '') {
                Router::$response->status(503)->send(['message' => 'La traducción no está configurada: falta OPENAI_API_KEY en el servidor.']);
                return;
            }
        }
        $post = $this->groupPostsModel->getPostById($postId);
        if (($post['status'] ?? '') === 'ended') {
            Router::$response->status(200)->send(['caption' => null]);
            return;
        }
        try {
            $caption = (new \App\Services\LiveCaptionService())->read($postId);
            Router::$response->status(200)->send(['caption' => $caption ?: null]);
        } catch (\Throwable $e) {
            // Return only a category and source location; never expose exception text.
            $category = 'OTRO';
            $detail = strtolower($e->getMessage());
            foreach (['class' => 'CLASE', 'undefined' => 'FUNCION',
                'connection refused' => 'CONEXION', 'noauth' => 'REDIS-AUTH',
                'permission denied' => 'PERMISO', 'timeout' => 'TIEMPO',
                'failed opening' => 'ARCHIVO', 'open_basedir' => 'RUTA'] as $match => $label) {
                if (str_contains($detail, $match)) { $category = $label; break; }
            }
            $type = preg_replace('/[^A-Za-z0-9]/', '', get_class($e));
            $file = preg_replace('/[^A-Za-z0-9_.-]/', '', basename($e->getFile()));
            $reference = 'CAP-READ-' . $category . '-' . $type . '-' . $file . '-L' . $e->getLine();
            error_log($reference);
            Router::$response->status(503)->send(['message' => 'Subtítulos no disponibles. Diagnóstico: ' . $reference]);
        }
    }

    public function translateCaption()
    {
        $postId = (int) (Router::$request->params->postId ?? 0);
        $userId = (int) (Router::$request->user->id ?? 0);
        if (!$this->requireCaptionAccess($postId, $userId)) return;
        $post = $this->groupPostsModel->getPostById($postId);
        if (($post['type'] ?? '') !== 'live') {
            Router::$response->status(400)->send(['message' => 'El contenido no es un live.']);
            return;
        }
        if ((int) $post['user_id'] !== $userId) {
            Router::$response->status(403)->send(['message' => 'Solo el transmisor puede generar subtítulos.']);
            return;
        }
        if (($post['status'] ?? '') === 'ended') {
            Router::$response->status(409)->send(['message' => 'Este live ya terminó.']);
            return;
        }
        try {
            $caption = (new \App\Services\LiveCaptionService())->translate($postId, $_FILES['audio'] ?? [], is_string($_POST['target_language'] ?? 'en') ? ($_POST['target_language'] ?? 'en') : 'invalid');
            Router::$response->status(200)->send(['caption' => $caption]);
        } catch (\Throwable $e) {
            $code = (int) $e->getCode();
            $known = in_array($code, [400, 415, 429, 502, 503], true);
            Router::$response->status($known ? $code : 503)->send(['message' => $known ? $e->getMessage() : 'No se pudo iniciar la traducción en el servidor.']);
        }
    }

    private function requireCaptionAccess(int $postId, int $userId): bool
    {
        if ($userId <= 0) {
            Router::$response->status(401)->send(['message' => 'Sesión vencida. Vuelve a iniciar sesión para traducir.']);
            return false;
        }
        $post = $this->groupPostsModel->getPostById($postId);
        if (!$post) {
            Router::$response->status(404)->send(['message' => 'No se pudo consultar este live en el servidor. Se necesita revisar el registro PHP.']);
            return false;
        }
        if (!$this->groupsModel->isUserInGroup((int) $post['group_id'], $userId)) {
            Router::$response->status(403)->send(['message' => 'Tu cuenta no figura como miembro activo del grupo del live.']);
            return false;
        }
        if (!$this->requireLiveAccess($postId, $userId, false)) {
            $message = !empty($post['is_adult_content'])
                ? 'Acceso rechazado. Este live es +18: comprueba la verificación de edad y las restricciones de tu cuenta.'
                : 'Acceso rechazado al live. Comprueba invitación, expulsión o bloqueo.';
            Router::$response->status(403)->send(['message' => $message]);
            return false;
        }
        return true;
    }

    private function requireLiveAccess(int $postId, int $userId, bool $respond = true): bool
    {
        $post = $this->groupPostsModel->getPostById($postId);
        $allowed = $post && $userId > 0 && $this->groupsModel->isUserInGroup((int) $post['group_id'], $userId)
            && !$this->groupPostsModel->isViewerKicked($postId, $userId)
            && !$this->groupPostsModel->isViewerBlocked((int) $post['group_id'], $userId, $postId)
            && (($post['visibility'] ?? '') !== 'private' || (int) $post['user_id'] === $userId || $this->groupPostsModel->isInvitedViewer($postId, $userId));
        if ($allowed && !empty($post['is_adult_content'])) $allowed = !$this->familyModel->isBlockedFromAdultContent($userId) && $this->usersModel->getAgeVerificationStatus($userId) === 'approved';
        if (!$allowed && $respond) Router::$response->status(403)->send(['message' => 'No tienes acceso a este live']);
        return (bool) $allowed;
    }

    private function isInternalRequest(): bool
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return in_array($ip, ['127.0.0.1', '::1'], true);
    }
}
