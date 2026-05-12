<?php

use Ratchet\ConnectionInterface;

class SignalServer implements \Ratchet\MessageComponentInterface
{
    protected $clients;
    protected $sessions = [];
    protected $userConnections = [];
    protected $calls = [];
    protected $statusManager;
    protected $userTimers = [];
    protected $chatModel;
    protected $usersModel;
    protected $profileModel;

    public function __construct()
    {
        $this->clients = new \SplObjectStorage();
        $this->statusManager = new UserStatusManager();
        $this->initializeChatModel();
        $this->initializeUsersModel();
        $this->initializeProfileModel();
        echo "🚀 SignalServer refactorizado inicializado\n";
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
        $conn->authenticated = false;
        $conn->userId = null;
        $conn->userData = [];
        $conn->joinedChats = [];
        $conn->openedAt = time();

        $this->sendJson($conn, [
            'type' => 'welcome',
            'message' => 'WebSocket conectado',
            'connection_id' => $conn->resourceId,
            'server_time' => $this->nowIso(),
        ], false);
    }

    public function onClose(ConnectionInterface $conn)
    {
        $this->cleanupConnection($conn, 'closed');
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        $this->logToFile("⚠️ Error en conexión #{$conn->resourceId}: {$e->getMessage()}");
        $this->cleanupConnection($conn, 'error');
        try {
            $conn->close();
        } catch (\Throwable $closeError) {
        }
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        if (!is_string($msg)) {
            $this->sendError($from, 'Formato de mensaje no soportado');
            return;
        }

        $data = json_decode($msg, true);
        if (!is_array($data)) {
            $this->sendError($from, 'JSON inválido');
            return;
        }

        $type = trim((string)($data['type'] ?? ''));
        if ($type === '') {
            $this->sendError($from, 'Falta type');
            return;
        }

        try {
            switch ($type) {
                case 'identify':
                    $this->handleIdentify($from, $data);
                    return;

                case 'auth':
                    $this->handleAuth($from, $data);
                    return;

                case 'ping':
                    $this->handlePing($from);
                    return;

                case 'heartbeat':
                    $this->handleHeartbeat($from, $data);
                    return;

                case 'join_chat':
                    $this->handleJoinChat($from, $data);
                    return;

                case 'typing':
                    $this->handleTyping($from, $data);
                    return;

                case 'chat_message':
                    $this->handleChatMessage($from, $data);
                    return;

                case 'file_upload':
                case 'image_upload':
                    $this->handleFileUpload($from, $data);
                    return;

                case 'mark_as_read':
                    $this->handleMarkAsRead($from, $data);
                    return;

                case 'get_online_users':
                    $this->handleGetOnlineUsers($from, $data);
                    return;

                case 'get_user_status':
                    $this->handleGetUserStatus($from, $data);
                    return;

                case 'init_call':
                case 'call_request':
                case 'offer':
                case 'call_offer':
                    $this->handleInitCall($from, $data);
                    return;

                case 'answer':
                case 'call_answer':
                    $this->handleCallAnswer($from, $data);
                    return;

                case 'candidate':
                case 'ice_candidate':
                case 'call_candidate':
                    $this->handleCallCandidate($from, $data);
                    return;

                case 'accept_call':
                case 'call_accepted':
                    $this->handleLegacyAcceptCall($from, $data);
                    return;

                case 'reject_call':
                case 'call_reject':
                case 'call_rejected':
                    $this->handleCallReject($from, $data);
                    return;

                case 'call_ended':
                    $this->handleCallEnded($from, $data);
                    return;

                default:
                    $this->sendError($from, 'Tipo no soportado: ' . $type, ['type' => $type], 'unsupported_type');
                    return;
            }
        } catch (\Throwable $e) {
            $this->logToFile("❌ Error procesando {$type}: {$e->getMessage()}");
            $this->logToFile($e->getTraceAsString());
            $this->sendError($from, 'Error procesando mensaje', ['type' => $type], 'server_error');
        }
    }

    private function initializeChatModel()
    {
        try {
            if (!class_exists('App\Models\ChatModel')) {
                $this->chatModel = null;
                return;
            }
            $this->chatModel = new \App\Models\ChatModel();
        } catch (\Throwable $e) {
            $this->chatModel = null;
            $this->logToFile("❌ ChatModel no disponible: {$e->getMessage()}");
        }
    }

    private function initializeUsersModel()
    {
        try {
            if (!class_exists('App\Models\UsersModel')) {
                $this->usersModel = null;
                return;
            }
            $this->usersModel = new \App\Models\UsersModel();
        } catch (\Throwable $e) {
            $this->usersModel = null;
            $this->logToFile("❌ UsersModel no disponible: {$e->getMessage()}");
        }
    }

    private function initializeProfileModel()
    {
        try {
            if (!class_exists('App\Models\ProfileModel')) {
                $this->profileModel = null;
                return;
            }
            $this->profileModel = new \App\Models\ProfileModel();
        } catch (\Throwable $e) {
            $this->profileModel = null;
            $this->logToFile("❌ ProfileModel no disponible: {$e->getMessage()}");
        }
    }

    private function nowIso()
    {
        return date('c');
    }

    private function logToFile($message)
    {
        $logFile = __DIR__ . '/websocket_debug.log';
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
        if (php_sapi_name() === 'cli') {
            echo $line;
        }
    }

    private function isSignalPayloadType($type)
    {
        return in_array((string)$type, [
            'incoming_call',
            'call_initiated',
            'call_answer',
            'ice_candidate',
            'call_rejected',
            'call_ended',
            'call_status',
        ], true);
    }

    private function traceSignal($stage, array $context = [])
    {
        $parts = [];
        foreach ($context as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif (is_array($value)) {
                $value = implode(',', array_map('strval', $value));
            } elseif (is_object($value)) {
                $value = method_exists($value, '__toString') ? (string)$value : get_class($value);
            }
            $parts[] = $key . '=' . $value;
        }

        $message = trim($stage . (empty($parts) ? '' : ' ' . implode(' ', $parts)));
        $this->logToFile($message);

        if (function_exists('tuani_ws_signal_log')) {
            tuani_ws_signal_log($message);
        }
    }

    private function sendJson(ConnectionInterface $conn, array $payload, $cleanupOnFailure = true)
    {
        try {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new \RuntimeException('No se pudo serializar JSON');
            }
            $conn->send($json);
            return true;
        } catch (\Throwable $e) {
            $this->logToFile("❌ Error enviando a #{$conn->resourceId}: {$e->getMessage()}");
            if ($cleanupOnFailure) {
                $this->cleanupConnection($conn, 'send_failure');
            }
            return false;
        }
    }

    private function sendError(ConnectionInterface $conn, $message, array $extra = [], $code = 'bad_request')
    {
        $payload = array_merge([
            'type' => 'error',
            'error_code' => $code,
            'message' => $message,
            'timestamp' => $this->nowIso(),
        ], $extra);

        $this->sendJson($conn, $payload, false);
    }

    private function bindConnectionToUser(ConnectionInterface $conn, $userId, array $userData = [], $markOnline = true, $sendAck = false)
    {
        $userId = (int)$userId;
        if ($userId < 1) {
            return false;
        }

        $connId = $conn->resourceId;
        $previousUserId = isset($conn->userId) ? (int)$conn->userId : 0;
        if ($previousUserId > 0 && $previousUserId !== $userId) {
            $this->removeConnectionFromUser($conn, $previousUserId, false);
        }

        $wasOnline = $markOnline ? $this->statusManager->isOnline($userId) : false;

        if (!isset($this->userConnections[$userId])) {
            $this->userConnections[$userId] = [];
        }
        $this->userConnections[$userId][$connId] = $conn;

        $conn->userId = $userId;
        if (!empty($userData)) {
            $conn->userData = $userData;
        } elseif (!isset($conn->userData) || !is_array($conn->userData)) {
            $conn->userData = [];
        }
        if ($markOnline) {
            $conn->authenticated = true;
        }

        if ($markOnline) {
            $this->statusManager->setOnline($userId, $connId, $conn->userData);
            $this->startHeartbeatTimer($conn);

            if (!$wasOnline) {
                $this->notifyUserStatusChange($userId, 'online', $this->statusManager->getUserStatus($userId));
            }
        }

        if ($sendAck) {
            $this->sendJson($conn, [
                'type' => 'auth_success',
                'user_id' => $userId,
                'connection_id' => $connId,
                'timestamp' => $this->nowIso(),
                'message' => 'Autenticación exitosa',
            ], false);
        }

        return true;
    }

    private function handleIdentify(ConnectionInterface $from, array $data)
    {
        $userId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
        if ($userId < 1) {
            $this->sendError($from, 'identify requiere user_id');
            return;
        }

        $this->bindConnectionToUser($from, $userId, $data['user_data'] ?? [], false, false);

        $this->sendJson($from, [
            'type' => 'identified',
            'user_id' => $userId,
            'connection_id' => $from->resourceId,
            'timestamp' => $this->nowIso(),
        ], false);
    }

    private function handleAuth(ConnectionInterface $from, array $data)
    {
        $userId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
        if ($userId < 1) {
            $this->sendError($from, 'auth requiere user_id');
            return;
        }

        $this->bindConnectionToUser($from, $userId, $data['user_data'] ?? [], true, true);
    }

    private function resolveSenderUserId(ConnectionInterface $conn, array $data = [])
    {
        $boundUserId = isset($conn->userId) ? (int)$conn->userId : 0;
        $declaredUserId = 0;
        foreach (['user_id', 'from'] as $field) {
            if (isset($data[$field]) && is_numeric($data[$field])) {
                $declaredUserId = (int)$data[$field];
                break;
            }
        }

        if ($boundUserId > 0 && $declaredUserId > 0 && $boundUserId !== $declaredUserId) {
            $this->sendError($conn, 'user_id no coincide con la conexión', [
                'bound_user_id' => $boundUserId,
                'declared_user_id' => $declaredUserId,
            ], 'invalid_user');
            return null;
        }

        if ($boundUserId > 0) {
            return $boundUserId;
        }

        if ($declaredUserId > 0) {
            $this->bindConnectionToUser($conn, $declaredUserId, [], false, false);
            return $declaredUserId;
        }

        return null;
    }

    private function normalizeTargetUserId(array $data)
    {
        foreach (['target_user_id', 'to', 'recipient_id'] as $field) {
            if (isset($data[$field]) && is_numeric($data[$field])) {
                $value = (int)$data[$field];
                if ($value > 0) {
                    return $value;
                }
            }
        }
        return null;
    }

    private function normalizeChatId(array $data)
    {
        foreach (['chat_id', 'chatId'] as $field) {
            if (isset($data[$field]) && is_numeric($data[$field])) {
                $value = (int)$data[$field];
                if ($value > 0) {
                    return $value;
                }
            }
        }
        return null;
    }

    private function normalizeCallId(array $data, $createIfMissing = false)
    {
        foreach (['call_id', 'session_id'] as $field) {
            if (!empty($data[$field])) {
                return (string)$data[$field];
            }
        }

        if ($createIfMissing) {
            return uniqid('call_', true);
        }

        return null;
    }

    private function normalizeSdp($raw, $expectedType = null)
    {
        if (is_string($raw) && trim($raw) !== '') {
            return [
                'type' => $expectedType ?: 'offer',
                'sdp' => $raw,
            ];
        }

        if (is_array($raw) && !empty($raw['sdp']) && is_string($raw['sdp'])) {
            $type = isset($raw['type']) && is_string($raw['type']) ? $raw['type'] : ($expectedType ?: 'offer');
            return [
                'type' => $type,
                'sdp' => $raw['sdp'],
            ];
        }

        return null;
    }

    private function normalizeCandidate($raw)
    {
        if (is_string($raw) && trim($raw) !== '') {
            return ['candidate' => $raw];
        }

        if (is_array($raw) && !empty($raw['candidate'])) {
            return $raw;
        }

        return null;
    }

    private function getConnectionsForUser($userId)
    {
        $userId = (int)$userId;
        if ($userId < 1 || empty($this->userConnections[$userId])) {
            return [];
        }

        $targets = [];
        foreach ($this->userConnections[$userId] as $connId => $conn) {
            if ($conn instanceof ConnectionInterface && $this->clients->contains($conn)) {
                $targets[$connId] = $conn;
            }
        }

        if (empty($targets)) {
            unset($this->userConnections[$userId]);
        }

        return $targets;
    }

    private function getPreferredUserTargets($userId, $preferredConnId = null)
    {
        $targets = $this->getConnectionsForUser($userId);
        if ($preferredConnId !== null && isset($targets[$preferredConnId])) {
            return [$preferredConnId => $targets[$preferredConnId]];
        }
        return $targets;
    }

    private function sendToConnections(array $targets, array $payload, ConnectionInterface $excludeConnection = null)
    {
        $sent = 0;
        foreach ($targets as $conn) {
            if ($excludeConnection && $conn === $excludeConnection) {
                continue;
            }
            if ($this->sendJson($conn, $payload)) {
                $sent++;
            }
        }
        return $sent;
    }

    private function sendToUser($userId, array $payload, ConnectionInterface $excludeConnection = null)
    {
        $targets = $this->getConnectionsForUser($userId);
        $sent = $this->sendToConnections($targets, $payload, $excludeConnection);

        if ($this->isSignalPayloadType($payload['type'] ?? '')) {
            $this->traceSignal('route_user', [
                'type' => $payload['type'] ?? 'unknown',
                'call_id' => $payload['call_id'] ?? ($payload['session_id'] ?? null),
                'user_id' => (int)$userId,
                'target_conn_ids' => array_keys($targets),
                'exclude_conn_id' => $excludeConnection ? $excludeConnection->resourceId : null,
                'sent' => $sent,
            ]);
        }

        return $sent;
    }

    private function sendToUserPreferred($userId, $preferredConnId, array $payload, ConnectionInterface $excludeConnection = null)
    {
        $targets = $this->getPreferredUserTargets($userId, $preferredConnId);
        $sent = $this->sendToConnections($targets, $payload, $excludeConnection);

        if ($sent === 0 && $preferredConnId !== null) {
            $sent = $this->sendToUser($userId, $payload, $excludeConnection);
        }

        if ($this->isSignalPayloadType($payload['type'] ?? '')) {
            $this->traceSignal('route_user_preferred', [
                'type' => $payload['type'] ?? 'unknown',
                'call_id' => $payload['call_id'] ?? ($payload['session_id'] ?? null),
                'user_id' => (int)$userId,
                'preferred_conn_id' => $preferredConnId,
                'target_conn_ids' => array_keys($targets),
                'exclude_conn_id' => $excludeConnection ? $excludeConnection->resourceId : null,
                'sent' => $sent,
            ]);
        }

        return $sent;
    }

    private function handlePing(ConnectionInterface $from)
    {
        $userId = isset($from->userId) ? (int)$from->userId : 0;
        if ($userId > 0) {
            $this->statusManager->updateActivity($userId);
        }

        $this->sendJson($from, [
            'type' => 'pong',
            'timestamp' => $this->nowIso(),
            'online' => $userId > 0,
        ], false);
    }

    private function handleHeartbeat(ConnectionInterface $from, array $data)
    {
        $userId = $this->resolveSenderUserId($from, $data);
        if (!$userId) {
            return;
        }

        $this->statusManager->updateActivity($userId);
        $this->sendJson($from, [
            'type' => 'heartbeat_response',
            'timestamp' => $this->nowIso(),
            'user_id' => $userId,
            'online' => true,
        ], false);
    }

    private function startHeartbeatTimer(ConnectionInterface $conn)
    {
        $connId = $conn->resourceId;

        if (isset($this->userTimers[$connId])) {
            try {
                \React\EventLoop\Loop::cancelTimer($this->userTimers[$connId]);
            } catch (\Throwable $e) {
            }
            unset($this->userTimers[$connId]);
        }

        $this->userTimers[$connId] = \React\EventLoop\Loop::addPeriodicTimer(25, function () use ($conn, $connId) {
            if (!$this->clients->contains($conn) || empty($conn->userId)) {
                if (isset($this->userTimers[$connId])) {
                    try {
                        \React\EventLoop\Loop::cancelTimer($this->userTimers[$connId]);
                    } catch (\Throwable $e) {
                    }
                    unset($this->userTimers[$connId]);
                }
                return;
            }

            $this->statusManager->updateActivity((int)$conn->userId);
            $this->sendJson($conn, [
                'type' => 'server_heartbeat',
                'timestamp' => $this->nowIso(),
                'online' => true,
            ]);
        });
    }

    private function cancelHeartbeatTimer(ConnectionInterface $conn)
    {
        $connId = $conn->resourceId;
        if (!isset($this->userTimers[$connId])) {
            return;
        }

        try {
            \React\EventLoop\Loop::cancelTimer($this->userTimers[$connId]);
        } catch (\Throwable $e) {
        }
        unset($this->userTimers[$connId]);
    }

    private function handleGetOnlineUsers(ConnectionInterface $from, array $data)
    {
        $onlineUsers = $this->statusManager->getOnlineUsers($data['limit'] ?? 100);
        $stats = $this->statusManager->getStats();

        $this->sendJson($from, [
            'type' => 'online_users_list',
            'users' => $onlineUsers,
            'stats' => $stats,
            'count' => count($onlineUsers),
            'timestamp' => $this->nowIso(),
        ], false);
    }

    private function handleGetUserStatus(ConnectionInterface $from, array $data)
    {
        if (!isset($data['user_id'])) {
            $this->sendError($from, 'Falta user_id');
            return;
        }

        $userIds = is_array($data['user_id']) ? $data['user_id'] : [$data['user_id']];
        $statuses = $this->statusManager->getUsersStatus($userIds);

        $this->sendJson($from, [
            'type' => 'users_status',
            'statuses' => $statuses,
            'timestamp' => $this->nowIso(),
        ], false);
    }

    private function isUserPresentInChat($chatId, $userId, $excludeConnId = null)
    {
        if (empty($this->sessions[$chatId])) {
            return false;
        }

        foreach ($this->sessions[$chatId] as $connId => $conn) {
            if ($excludeConnId !== null && (string)$connId === (string)$excludeConnId) {
                continue;
            }
            if (isset($conn->userId) && (int)$conn->userId === (int)$userId) {
                return true;
            }
        }

        return false;
    }

    private function handleJoinChat(ConnectionInterface $from, array $data)
    {
        $userId = $this->resolveSenderUserId($from, $data);
        $chatId = $this->normalizeChatId($data);

        if (!$userId || !$chatId) {
            $this->sendError($from, 'join_chat requiere user_id y chat_id');
            return;
        }

        $alreadyPresent = $this->isUserPresentInChat($chatId, $userId);

        if (!isset($this->sessions[$chatId])) {
            $this->sessions[$chatId] = [];
        }

        $this->sessions[$chatId][$from->resourceId] = $from;
        if (!is_array($from->joinedChats ?? null)) {
            $from->joinedChats = [];
        }
        $from->joinedChats[$chatId] = true;

        $onlineUsers = $this->getOnlineUsersInChat($chatId);
        $this->sendJson($from, [
            'type' => 'joined_chat',
            'chat_id' => $chatId,
            'user_id' => $userId,
            'online_count' => count($onlineUsers),
            'online_users' => $onlineUsers,
            'timestamp' => $this->nowIso(),
        ], false);

        if (!$alreadyPresent) {
            $this->notifyUserJoinedChat($chatId, $userId);
        }
    }

    private function getOnlineUsersInChat($chatId)
    {
        $users = [];
        if (empty($this->sessions[$chatId])) {
            return [];
        }

        foreach ($this->sessions[$chatId] as $conn) {
            if (!isset($conn->userId)) {
                continue;
            }
            $userId = (int)$conn->userId;
            if (!isset($users[$userId])) {
                $status = $this->statusManager->getUserStatus($userId);
                $status['user_id'] = $userId;
                $status['in_chat'] = true;
                $users[$userId] = $status;
            }
        }

        return array_values($users);
    }

    private function notifyUserJoinedChat($chatId, $userId)
    {
        if (empty($this->sessions[$chatId])) {
            return;
        }

        $message = [
            'type' => 'user_joined_chat',
            'chat_id' => (int)$chatId,
            'user_id' => (int)$userId,
            'status' => $this->statusManager->getUserStatus($userId),
            'timestamp' => $this->nowIso(),
        ];

        foreach ($this->sessions[$chatId] as $conn) {
            if (isset($conn->userId) && (int)$conn->userId === (int)$userId) {
                continue;
            }
            $this->sendJson($conn, $message);
        }
    }

    private function notifyUserLeftChat($chatId, $userId)
    {
        if (empty($this->sessions[$chatId])) {
            return;
        }

        $message = [
            'type' => 'user_left_chat',
            'chat_id' => (int)$chatId,
            'user_id' => (int)$userId,
            'timestamp' => $this->nowIso(),
        ];

        foreach ($this->sessions[$chatId] as $conn) {
            if (isset($conn->userId) && (int)$conn->userId === (int)$userId) {
                continue;
            }
            $this->sendJson($conn, $message);
        }
    }

    private function handleTyping(ConnectionInterface $from, array $data)
    {
        $userId = $this->resolveSenderUserId($from, $data);
        $chatId = $this->normalizeChatId($data);
        if (!$userId || !$chatId) {
            return;
        }

        $this->statusManager->updateActivity($userId);

        $this->broadcastToChat($chatId, [
            'type' => 'typing',
            'chat_id' => $chatId,
            'user_id' => $userId,
            'isTyping' => !empty($data['isTyping']),
            'timestamp' => $this->nowIso(),
        ], $from);
    }

    private function resolveChatId(array $data, $senderId, $createIfMissing = true)
    {
        $chatId = $this->normalizeChatId($data);
        $otherUserId = null;

        if (isset($data['other_user_id']) && is_numeric($data['other_user_id'])) {
            $otherUserId = (int)$data['other_user_id'];
        } else {
            $targetUserId = $this->normalizeTargetUserId($data);
            if ($targetUserId && $targetUserId !== (int)$senderId) {
                $otherUserId = $targetUserId;
            }
        }

        if (!$this->chatModel) {
            return $chatId;
        }

        try {
            if ($chatId && $this->chatModel->chatExists($chatId)) {
                return $chatId;
            }

            if ($otherUserId && $createIfMissing) {
                $existingChatId = $this->chatModel->findChatBetweenUsers((int)$senderId, $otherUserId);
                if ($existingChatId) {
                    return (int)$existingChatId;
                }
                return (int)$this->chatModel->createChat([(int)$senderId, $otherUserId]);
            }
        } catch (\Throwable $e) {
            $this->logToFile("❌ Error resolviendo chat: {$e->getMessage()}");
        }

        return $chatId;
    }

    private function getChatParticipantIds($chatId)
    {
        $participants = [];

        if ($this->chatModel && method_exists($this->chatModel, 'getChatParticipantIds')) {
            try {
                $participants = $this->chatModel->getChatParticipantIds((int)$chatId);
            } catch (\Throwable $e) {
                $this->logToFile("❌ Error obteniendo participantes de chat {$chatId}: {$e->getMessage()}");
            }
        }

        if (empty($participants) && !empty($this->sessions[$chatId])) {
            foreach ($this->sessions[$chatId] as $conn) {
                if (isset($conn->userId)) {
                    $participants[] = (int)$conn->userId;
                }
            }
        }

        $participants = array_values(array_unique(array_filter(array_map('intval', $participants))));
        return $participants;
    }

    private function getChatTargetConnections($chatId)
    {
        $targets = [];

        if (!empty($this->sessions[$chatId])) {
            foreach ($this->sessions[$chatId] as $connId => $conn) {
                if ($conn instanceof ConnectionInterface && $this->clients->contains($conn)) {
                    $targets[$connId] = $conn;
                }
            }
        }

        foreach ($this->getChatParticipantIds($chatId) as $participantId) {
            foreach ($this->getConnectionsForUser($participantId) as $connId => $conn) {
                $targets[$connId] = $conn;
            }
        }

        return $targets;
    }

    private function broadcastToChat($chatId, array $message, ConnectionInterface $excludeConnection = null)
    {
        return $this->sendToConnections($this->getChatTargetConnections($chatId), $message, $excludeConnection);
    }

    private function getMessagePreview($content, $type)
    {
        if ($type === 'imagen') {
            return '📷 Imagen';
        }
        if ($type === 'archivo') {
            return '📎 Archivo';
        }
        if ($type === 'audio') {
            return '🎵 Audio';
        }

        $content = trim((string)$content);
        if ($content === '') {
            return 'Nuevo mensaje';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($content) > 80 ? mb_substr($content, 0, 77) . '...' : $content;
        }

        return strlen($content) > 80 ? substr($content, 0, 77) . '...' : $content;
    }

    private function notifyChatListUpdate($chatId, array $messageData)
    {
        $payload = [
            'type' => 'chat_updated',
            'chat_id' => (int)$chatId,
            'sender_id' => $messageData['user_id'] ?? null,
            'last_message' => $this->getMessagePreview($messageData['contenido'] ?? '', $messageData['tipo'] ?? 'texto'),
            'last_message_time' => $messageData['timestamp'] ?? $this->nowIso(),
            'message_type' => $messageData['tipo'] ?? 'texto',
            'message_id' => $messageData['message_id'] ?? null,
            'unread_count' => 1,
            'action' => 'bump_to_top',
            'timestamp' => $this->nowIso(),
        ];

        $participants = $this->getChatParticipantIds($chatId);
        if (empty($participants)) {
            $this->broadcastToChat($chatId, $payload);
            return;
        }

        foreach ($participants as $participantId) {
            $this->sendToUser($participantId, $payload);
        }
    }

    private function notifyUnreadCount($chatId, $userId, $count)
    {
        $this->sendToUser($userId, [
            'type' => 'unread_count_update',
            'chat_id' => (int)$chatId,
            'user_id' => (int)$userId,
            'unread_count' => (int)$count,
            'timestamp' => $this->nowIso(),
        ]);
    }

    private function updateUnreadCounts($chatId, $senderId)
    {
        if (!$this->chatModel) {
            return;
        }

        try {
            $sql = "SELECT user_id FROM chat_usuarios WHERE chat_id = ? AND user_id != ?";
            $results = $this->chatModel->query($sql, [(int)$chatId, (int)$senderId]);

            foreach ($results as $row) {
                $userId = (int)($row['user_id'] ?? 0);
                if ($userId < 1) {
                    continue;
                }

                $countSql = "SELECT COUNT(*) AS unread_count
                    FROM mensajes
                    WHERE chat_id = ?
                    AND user_id != ?
                    AND leido = 0";
                $countResult = $this->chatModel->query($countSql, [(int)$chatId, $userId]);
                $count = isset($countResult[0]['unread_count']) ? (int)$countResult[0]['unread_count'] : 0;
                $this->notifyUnreadCount($chatId, $userId, $count);
            }
        } catch (\Throwable $e) {
            $this->logToFile("❌ Error actualizando no leídos: {$e->getMessage()}");
        }
    }

    private function pushOfflineMessageNotifications($chatId, array $messageData, $senderId)
    {
        if (!$this->chatModel || !$senderId) {
            return;
        }

        $senderName = $this->getUserDisplayName($senderId);
        foreach ($this->getChatParticipantIds($chatId) as $participantId) {
            if ((int)$participantId === (int)$senderId) {
                continue;
            }
            if (!empty($this->getConnectionsForUser($participantId))) {
                continue;
            }

            $this->sendFcmToUser($participantId, [
                'type' => 'new_message',
                'chat_id' => (string)$chatId,
                'sender_name' => $senderName,
                'body' => $this->getMessagePreview($messageData['contenido'] ?? '', $messageData['tipo'] ?? 'texto'),
            ]);
        }
    }

    private function getUnavailableAutoReplyForUser($userId)
    {
        if (!$this->profileModel || !$userId) {
            return null;
        }

        try {
            $profile = $this->profileModel->getProfile((int)$userId);
            if (!is_array($profile)) {
                return null;
            }

            $enabled = !empty($profile['enable_unavailable_auto_reply']);
            $message = trim((string)($profile['unavailable_auto_reply_message'] ?? ''));

            if (!$enabled || $message === '') {
                return null;
            }

            return $message;
        } catch (\Throwable $e) {
            $this->logToFile("❌ Error obteniendo auto reply de {$userId}: {$e->getMessage()}");
            return null;
        }
    }

    private function maybeSendUnavailableAutoReply($chatId, $senderId)
    {
        if (!$this->chatModel || !$senderId) {
            return;
        }

        $participants = $this->getChatParticipantIds($chatId);
        if (count($participants) !== 2) {
            return;
        }

        foreach ($participants as $participantId) {
            if ((int)$participantId === (int)$senderId) {
                continue;
            }

            if (!empty($this->getConnectionsForUser($participantId))) {
                continue;
            }

            $autoReplyMessage = $this->getUnavailableAutoReplyForUser($participantId);
            if ($autoReplyMessage === null) {
                continue;
            }

            try {
                $messageId = $this->chatModel->sendMessage((int)$chatId, (int)$participantId, $autoReplyMessage, 'texto', null, (int)$senderId);
            } catch (\Throwable $e) {
                $this->logToFile("❌ Error guardando auto reply en chat {$chatId}: {$e->getMessage()}");
                return;
            }

            $messagePayload = [
                'type' => 'chat_message',
                'message_id' => $messageId,
                'chat_id' => (int)$chatId,
                'user_id' => (int)$participantId,
                'contenido' => $autoReplyMessage,
                'tipo' => 'texto',
                'timestamp' => $this->nowIso(),
                'leido' => 0,
                'user_name' => $this->getUserDisplayName($participantId),
                'status' => 'sent',
                'action' => 'auto_reply',
                'is_auto_reply' => true,
            ];

            $this->broadcastToChat($chatId, $messagePayload);
            $this->notifyChatListUpdate($chatId, $messagePayload);
            $this->updateUnreadCounts($chatId, $participantId);
            $this->logToFile("🤖 Auto reply enviado en chat {$chatId} por usuario {$participantId}");
            return;
        }
    }

    private function getUserDisplayName($userId)
    {
        if ($this->usersModel) {
            try {
                $user = $this->usersModel->getUser((int)$userId);
                if (is_array($user) && !empty($user['name'])) {
                    return $user['name'];
                }
            } catch (\Throwable $e) {
            }
        }

        foreach ($this->getConnectionsForUser($userId) as $conn) {
            if (!empty($conn->userData['name'])) {
                return $conn->userData['name'];
            }
        }

        return 'Usuario';
    }

    private function handleChatMessage(ConnectionInterface $from, array $data)
    {
        $userId = $this->resolveSenderUserId($from, $data);
        $chatId = $this->resolveChatId($data, $userId, true);
        $tipo = trim((string)($data['tipo'] ?? 'texto'));
        $contenido = (string)($data['contenido'] ?? '');
        $tempId = $data['temp_id'] ?? null;

        if (!$userId || !$chatId) {
            $this->sendError($from, 'chat_message requiere user_id y chat_id');
            return;
        }

        if ($tipo === 'texto' && trim($contenido) === '') {
            $this->sendError($from, 'El mensaje está vacío', ['temp_id' => $tempId], 'empty_message');
            return;
        }

        $this->statusManager->updateActivity($userId);

        if ($tempId) {
            $this->sendJson($from, [
                'type' => 'message_ack',
                'temp_id' => $tempId,
                'status' => 'received',
                'timestamp' => $this->nowIso(),
            ], false);
        }

        try {
            $messageId = $this->chatModel
                ? $this->chatModel->sendMessage($chatId, $userId, $contenido, $tipo, null, $data['other_user_id'] ?? null)
                : ('local_' . uniqid());
        } catch (\Throwable $e) {
            $this->logToFile("❌ Error guardando mensaje: {$e->getMessage()}");
            if ($tempId) {
                $this->sendJson($from, [
                    'type' => 'message_error',
                    'temp_id' => $tempId,
                    'error' => $e->getMessage(),
                    'status' => 'failed',
                    'timestamp' => $this->nowIso(),
                ], false);
            }
            return;
        }

        if ($tempId) {
            $this->sendJson($from, [
                'type' => 'message_sent',
                'message_id' => $messageId,
                'temp_id' => $tempId,
                'chat_id' => $chatId,
                'user_id' => $userId,
                'contenido' => $contenido,
                'tipo' => $tipo,
                'timestamp' => $this->nowIso(),
                'status' => 'sent',
            ], false);
        }

        $messagePayload = [
            'type' => 'chat_message',
            'message_id' => $messageId,
            'chat_id' => $chatId,
            'user_id' => $userId,
            'contenido' => $contenido,
            'tipo' => $tipo,
            'timestamp' => $this->nowIso(),
            'temp_id' => $tempId,
            'leido' => 0,
            'user_name' => $data['user_name'] ?? $this->getUserDisplayName($userId),
            'status' => 'sent',
            'action' => 'new_message',
        ];

        $this->broadcastToChat($chatId, $messagePayload);
        $this->notifyChatListUpdate($chatId, $messagePayload);
        $this->updateUnreadCounts($chatId, $userId);
        $this->pushOfflineMessageNotifications($chatId, $messagePayload, $userId);
        $this->maybeSendUnavailableAutoReply($chatId, $userId);
    }

    private function handleFileUpload(ConnectionInterface $from, array $data)
    {
        $userId = $this->resolveSenderUserId($from, $data);
        $chatId = $this->resolveChatId($data, $userId, true);
        $tempId = $data['temp_id'] ?? null;

        if (!$userId || !$chatId) {
            $this->sendError($from, 'file_upload requiere user_id y chat_id');
            return;
        }

        $mimeType = (string)($data['file_mime_type'] ?? $data['mime_type'] ?? ($data['file_info']['mime_type'] ?? 'application/octet-stream'));
        $tipo = trim((string)($data['tipo'] ?? ((strpos($mimeType, 'image/') === 0 || ($data['type'] ?? '') === 'image_upload') ? 'imagen' : 'archivo')));
        $contenido = (string)($data['contenido'] ?? $data['file_original_name'] ?? $data['file_name'] ?? 'Archivo');

        $fileId = null;
        try {
            if ($this->chatModel && (!empty($data['file_url']) || !empty($data['url']) || !empty($data['file_info']))) {
                $fileId = $this->chatModel->saveFile([
                    'name' => $data['file_name'] ?? ($data['file_info']['name'] ?? basename($data['file_url'] ?? $data['url'] ?? 'archivo')),
                    'original_name' => $data['file_original_name'] ?? $contenido,
                    'path' => $data['file_path'] ?? ($data['file_info']['path'] ?? ''),
                    'url' => $data['file_url'] ?? $data['url'] ?? ($data['file_info']['url'] ?? ''),
                    'size' => (int)($data['file_size'] ?? ($data['file_info']['size'] ?? 0)),
                    'mime_type' => $mimeType,
                    'chat_id' => $chatId,
                    'user_id' => $userId,
                ]);
            }

            $messageId = $this->chatModel
                ? $this->chatModel->sendMessage($chatId, $userId, $contenido, $tipo, $fileId, $data['other_user_id'] ?? null)
                : ('local_' . uniqid());
        } catch (\Throwable $e) {
            $this->logToFile("❌ Error procesando archivo: {$e->getMessage()}");
            if ($tempId) {
                $this->sendJson($from, [
                    'type' => 'message_error',
                    'temp_id' => $tempId,
                    'error' => $e->getMessage(),
                    'status' => 'failed',
                    'timestamp' => $this->nowIso(),
                ], false);
            }
            return;
        }

        if ($tempId) {
            $this->sendJson($from, [
                'type' => 'message_sent',
                'message_id' => $messageId,
                'temp_id' => $tempId,
                'chat_id' => $chatId,
                'user_id' => $userId,
                'contenido' => $contenido,
                'tipo' => $tipo,
                'timestamp' => $this->nowIso(),
                'status' => 'sent',
            ], false);
        }

        $messagePayload = [
            'type' => ($data['type'] ?? 'file_upload') === 'image_upload' ? 'image_upload' : 'file_upload',
            'message_id' => $messageId,
            'chat_id' => $chatId,
            'user_id' => $userId,
            'contenido' => $contenido,
            'tipo' => $tipo,
            'timestamp' => $this->nowIso(),
            'temp_id' => $tempId,
            'leido' => 0,
            'status' => 'delivered',
            'file_id' => $fileId,
            'file_url' => $data['file_url'] ?? $data['url'] ?? ($data['file_info']['url'] ?? null),
            'file_info' => $data['file_info'] ?? null,
            'file_original_name' => $data['file_original_name'] ?? null,
            'file_name' => $data['file_name'] ?? null,
            'file_size' => $data['file_size'] ?? ($data['file_info']['size'] ?? null),
            'file_mime_type' => $mimeType,
            'user_name' => $data['user_name'] ?? $this->getUserDisplayName($userId),
            'action' => 'file_uploaded',
        ];

        $this->broadcastToChat($chatId, $messagePayload);
        $this->notifyChatListUpdate($chatId, $messagePayload);
        $this->updateUnreadCounts($chatId, $userId);
        $this->pushOfflineMessageNotifications($chatId, $messagePayload, $userId);
    }

    private function handleMarkAsRead(ConnectionInterface $from, array $data)
    {
        $userId = $this->resolveSenderUserId($from, $data);
        $chatId = $this->normalizeChatId($data);
        if (!$userId || !$chatId || !$this->chatModel) {
            return;
        }

        try {
            $count = (int)$this->chatModel->markMessagesAsRead($chatId, $userId);
            $this->sendJson($from, [
                'type' => 'messages_read_ack',
                'chat_id' => $chatId,
                'user_id' => $userId,
                'count' => $count,
                'timestamp' => $this->nowIso(),
            ], false);

            $this->notifyMessagesRead($chatId, $userId, $count);
            $this->notifyUnreadCount($chatId, $userId, 0);
        } catch (\Throwable $e) {
            $this->logToFile("❌ Error marcando mensajes como leídos: {$e->getMessage()}");
        }
    }

    private function notifyMessagesRead($chatId, $userId, $count)
    {
        $payload = [
            'type' => 'messages_read',
            'chat_id' => (int)$chatId,
            'user_id' => (int)$userId,
            'count' => (int)$count,
            'timestamp' => $this->nowIso(),
        ];

        foreach ($this->getChatParticipantIds($chatId) as $participantId) {
            if ((int)$participantId === (int)$userId) {
                continue;
            }
            $this->sendToUser($participantId, $payload);
        }
    }

    private function notifyUserStatusChange($userId, $status, array $data = [])
    {
        $payload = [
            'type' => 'user_status_change',
            'user_id' => (int)$userId,
            'status' => $status,
            'timestamp' => $this->nowIso(),
            'data' => $data,
        ];

        $this->sendToUser($userId, $payload);

        foreach ($this->sessions as $chatId => $connections) {
            if (!$this->isUserPresentInChat($chatId, $userId)) {
                continue;
            }

            foreach ($connections as $conn) {
                if (isset($conn->userId) && (int)$conn->userId === (int)$userId) {
                    continue;
                }
                $this->sendJson($conn, $payload);
            }
        }
    }

    private function sendFcmToUser($userId, array $data)
    {
        if (!$this->usersModel || !class_exists('App\Services\FcmService')) {
            return;
        }

        try {
            $token = $this->usersModel->getFcmToken((int)$userId);
            if ($token) {
                \App\Services\FcmService::sendDataMessage($token, $data);
            }
        } catch (\Throwable $e) {
            $this->logToFile("❌ Error enviando FCM a {$userId}: {$e->getMessage()}");
        }
    }

    private function buildCall(array $data, $callerId, $calleeId, ConnectionInterface $from)
    {
        $callId = $this->normalizeCallId($data, true);
        $chatId = $this->normalizeChatId($data);
        $offer = $this->normalizeSdp($data['sdp'] ?? null, 'offer');

        $call = $this->calls[$callId] ?? [
            'call_id' => $callId,
            'session_id' => $callId,
            'chat_id' => $chatId,
            'caller_id' => (int)$callerId,
            'callee_id' => (int)$calleeId,
            'caller_conn_id' => $from->resourceId,
            'callee_conn_id' => null,
            'call_type' => (($data['call_type'] ?? 'audio') === 'video') ? 'video' : 'audio',
            'status' => 'ringing',
            'offer' => null,
            'answer' => null,
            'created_at' => time(),
            'updated_at' => time(),
        ];

        $call['chat_id'] = $chatId ?: ($call['chat_id'] ?? null);
        $call['caller_id'] = (int)$callerId;
        $call['callee_id'] = (int)$calleeId;
        $call['caller_conn_id'] = $from->resourceId;
        $call['call_type'] = (($data['call_type'] ?? $call['call_type'] ?? 'audio') === 'video') ? 'video' : 'audio';
        $call['status'] = 'ringing';
        $call['updated_at'] = time();
        if ($offer) {
            $call['offer'] = $offer;
        }

        $this->calls[$callId] = $call;
        return $call;
    }

    private function touchCall(array $call)
    {
        $call['updated_at'] = time();
        $this->calls[$call['call_id']] = $call;
        return $call;
    }

    private function getCallSignalTargets(array $call, $fromUserId, $preferAcceptedPeer = true)
    {
        $fromUserId = (int)$fromUserId;
        if ($fromUserId === (int)$call['caller_id']) {
            if ($preferAcceptedPeer && !empty($call['callee_conn_id'])) {
                return $this->getPreferredUserTargets($call['callee_id'], $call['callee_conn_id']);
            }
            return $this->getConnectionsForUser($call['callee_id']);
        }

        if ($fromUserId === (int)$call['callee_id']) {
            return $this->getPreferredUserTargets($call['caller_id'], $call['caller_conn_id'] ?? null);
        }

        return [];
    }

    private function handleInitCall(ConnectionInterface $from, array $data)
    {
        $callerId = $this->resolveSenderUserId($from, $data);
        $calleeId = $this->normalizeTargetUserId($data);
        $chatId = $this->normalizeChatId($data);
        $offer = $this->normalizeSdp($data['sdp'] ?? null, 'offer');

        $this->traceSignal('recv_offer', [
            'conn_id' => $from->resourceId,
            'call_id' => $this->normalizeCallId($data),
            'caller_id' => $callerId,
            'callee_id' => $calleeId,
            'chat_id' => $chatId,
            'has_offer' => $offer !== null,
        ]);

        if (!$callerId || !$calleeId || !$chatId || !$offer) {
            $this->sendError($from, 'init_call requiere from, target_user_id/to, chat_id y sdp offer');
            return;
        }

        $call = $this->buildCall($data, $callerId, $calleeId, $from);
        $callerName = trim((string)($data['caller_name'] ?? $this->getUserDisplayName($callerId)));

        $incomingPayload = [
            'type' => 'incoming_call',
            'signal_type' => 'offer',
            'call_id' => $call['call_id'],
            'session_id' => $call['session_id'],
            'chat_id' => $chatId,
            'from' => $callerId,
            'to' => $calleeId,
            'target_user_id' => $calleeId,
            'caller_name' => $callerName !== '' ? $callerName : 'Usuario',
            'call_type' => $call['call_type'],
            'status' => 'ringing',
            'sdp' => $offer,
            'timestamp' => $this->nowIso(),
        ];

        $sentToPeer = $this->sendToUser($calleeId, $incomingPayload);

        $this->sendJson($from, [
            'type' => 'call_initiated',
            'signal_type' => 'offer',
            'call_id' => $call['call_id'],
            'session_id' => $call['session_id'],
            'chat_id' => $chatId,
            'from' => $callerId,
            'to' => $calleeId,
            'target_user_id' => $calleeId,
            'call_type' => $call['call_type'],
            'status' => $sentToPeer > 0 ? 'ringing' : 'recipient_offline',
            'peer_ws_connected' => $sentToPeer > 0,
            'timestamp' => $this->nowIso(),
        ], false);

        if ($sentToPeer === 0) {
            $this->sendFcmToUser($calleeId, [
                'type' => 'incoming_call',
                'caller_name' => $callerName !== '' ? $callerName : 'Usuario',
                'session_id' => (string)$call['session_id'],
                'chat_id' => (string)$chatId,
                'from' => (string)$callerId,
                'call_type' => $call['call_type'],
            ]);
        }

        $this->traceSignal('sent_incoming_call', [
            'call_id' => $call['call_id'],
            'caller_id' => $callerId,
            'callee_id' => $calleeId,
            'peer_ws' => $sentToPeer > 0,
            'status' => $sentToPeer > 0 ? 'ringing' : 'recipient_offline',
        ]);
    }

    private function handleCallAnswer(ConnectionInterface $from, array $data)
    {
        $calleeId = $this->resolveSenderUserId($from, $data);
        $callId = $this->normalizeCallId($data);
        $answer = $this->normalizeSdp($data['sdp'] ?? null, 'answer');

        $this->traceSignal('recv_answer', [
            'conn_id' => $from->resourceId,
            'call_id' => $callId,
            'callee_id' => $calleeId,
            'has_answer' => $answer !== null,
        ]);

        if (!$calleeId || !$callId || !$answer) {
            $this->sendError($from, 'call_answer requiere session_id/call_id y sdp answer');
            return;
        }

        $call = $this->calls[$callId] ?? null;
        if (!$call) {
            $this->sendError($from, 'La llamada no existe', ['call_id' => $callId], 'call_not_found');
            return;
        }

        if ((int)$call['callee_id'] !== (int)$calleeId) {
            $this->sendError($from, 'Solo el destinatario puede responder esta llamada', ['call_id' => $callId], 'invalid_participant');
            return;
        }

        $call['status'] = 'accepted';
        $call['callee_conn_id'] = $from->resourceId;
        $call['answer'] = $answer;
        $call = $this->touchCall($call);

        $payload = [
            'type' => 'call_answer',
            'signal_type' => 'answer',
            'call_id' => $callId,
            'session_id' => $callId,
            'chat_id' => $call['chat_id'],
            'from' => $calleeId,
            'to' => (int)$call['caller_id'],
            'target_user_id' => (int)$call['caller_id'],
            'sdp' => $answer,
            'timestamp' => $this->nowIso(),
        ];

        $sentToCaller = $this->sendToUserPreferred($call['caller_id'], $call['caller_conn_id'] ?? null, $payload);
        $sentToOtherCalleeSockets = $this->sendToUser($call['callee_id'], [
            'type' => 'call_ended',
            'call_id' => $callId,
            'session_id' => $callId,
            'chat_id' => $call['chat_id'],
            'from' => (int)$call['caller_id'],
            'to' => (int)$call['callee_id'],
            'reason' => 'answered_elsewhere',
            'timestamp' => $this->nowIso(),
        ], $from);

        $this->traceSignal('sent_answer', [
            'call_id' => $callId,
            'callee_id' => $calleeId,
            'caller_id' => (int)$call['caller_id'],
            'sent_to_caller' => $sentToCaller,
            'closed_other_callee_sockets' => $sentToOtherCalleeSockets,
        ]);
    }

    private function handleLegacyAcceptCall(ConnectionInterface $from, array $data)
    {
        $calleeId = $this->resolveSenderUserId($from, $data);
        $callId = $this->normalizeCallId($data);
        if (!$calleeId || !$callId || empty($this->calls[$callId])) {
            return;
        }

        $call = $this->calls[$callId];
        if ((int)$call['callee_id'] !== (int)$calleeId) {
            return;
        }

        $call['status'] = 'accepted';
        $call['callee_conn_id'] = $from->resourceId;
        $this->calls[$callId] = $this->touchCall($call);

        $this->sendToUserPreferred($call['caller_id'], $call['caller_conn_id'] ?? null, [
            'type' => 'call_status',
            'status' => 'accepted',
            'call_id' => $callId,
            'session_id' => $callId,
            'chat_id' => $call['chat_id'],
            'from' => $calleeId,
            'to' => (int)$call['caller_id'],
            'target_user_id' => (int)$call['caller_id'],
            'timestamp' => $this->nowIso(),
        ]);
    }

    private function handleCallCandidate(ConnectionInterface $from, array $data)
    {
        $fromUserId = $this->resolveSenderUserId($from, $data);
        $callId = $this->normalizeCallId($data);
        $candidate = $this->normalizeCandidate($data['candidate'] ?? null);

        $this->traceSignal('recv_candidate', [
            'conn_id' => $from->resourceId,
            'call_id' => $callId,
            'from_user_id' => $fromUserId,
            'target_user_id' => $this->normalizeTargetUserId($data),
            'sdp_mid' => is_array($candidate) ? ($candidate['sdpMid'] ?? null) : null,
            'sdp_mline_index' => is_array($candidate) ? ($candidate['sdpMLineIndex'] ?? null) : null,
            'has_candidate' => $candidate !== null,
        ]);

        if (!$fromUserId || !$callId || !$candidate) {
            $this->sendError($from, 'candidate requiere session_id/call_id y candidate');
            return;
        }

        $call = $this->calls[$callId] ?? null;
        $targetUserId = $this->normalizeTargetUserId($data);
        $targets = [];

        if ($call) {
            if ($fromUserId !== (int)$call['caller_id'] && $fromUserId !== (int)$call['callee_id']) {
                $this->sendError($from, 'Usuario no pertenece a la llamada', ['call_id' => $callId], 'invalid_participant');
                return;
            }

            if ($fromUserId === (int)$call['caller_id']) {
                $targetUserId = (int)$call['callee_id'];
            } else {
                $targetUserId = (int)$call['caller_id'];
            }

            $targets = $this->getCallSignalTargets($call, $fromUserId, true);
        } else {
            if (!$targetUserId) {
                $this->sendError($from, 'No se pudo resolver target_user_id para candidate');
                return;
            }
            $targets = $this->getConnectionsForUser($targetUserId);
        }

        if (empty($targets)) {
            $this->traceSignal('candidate_no_target', [
                'call_id' => $callId,
                'from_user_id' => $fromUserId,
                'target_user_id' => $targetUserId,
            ]);
            return;
        }

        $payload = [
            'type' => 'ice_candidate',
            'signal_type' => 'candidate',
            'call_id' => $callId,
            'session_id' => $callId,
            'chat_id' => $call['chat_id'] ?? $this->normalizeChatId($data),
            'from' => $fromUserId,
            'to' => $targetUserId,
            'target_user_id' => $targetUserId,
            'candidate' => $candidate,
            'timestamp' => $this->nowIso(),
        ];

        $sent = $this->sendToConnections($targets, $payload);
        $this->traceSignal('sent_candidate', [
            'call_id' => $callId,
            'from_user_id' => $fromUserId,
            'target_user_id' => $targetUserId,
            'target_conn_ids' => array_keys($targets),
            'sent' => $sent,
        ]);
    }

    private function markCallEnded($callId, $reason, $endedByUserId = null)
    {
        if (empty($this->calls[$callId])) {
            return null;
        }

        $call = $this->calls[$callId];
        $call['status'] = 'ended';
        $call['reason'] = $reason;
        $call['ended_by'] = $endedByUserId;
        $call['updated_at'] = time();
        $this->calls[$callId] = $call;
        return $call;
    }

    private function handleCallReject(ConnectionInterface $from, array $data)
    {
        $calleeId = $this->resolveSenderUserId($from, $data);
        $callId = $this->normalizeCallId($data);
        $reason = trim((string)($data['reason'] ?? 'rejected'));

        $this->traceSignal('recv_reject', [
            'conn_id' => $from->resourceId,
            'call_id' => $callId,
            'callee_id' => $calleeId,
            'reason' => $reason,
        ]);

        if (!$calleeId || !$callId) {
            return;
        }

        $call = $this->calls[$callId] ?? null;
        if (!$call) {
            return;
        }

        if ((int)$call['callee_id'] !== (int)$calleeId) {
            return;
        }

        $call = $this->markCallEnded($callId, $reason, $calleeId);

        $payload = [
            'type' => 'call_rejected',
            'call_id' => $callId,
            'session_id' => $callId,
            'chat_id' => $call['chat_id'],
            'from' => $calleeId,
            'to' => (int)$call['caller_id'],
            'target_user_id' => (int)$call['caller_id'],
            'reason' => $reason,
            'timestamp' => $this->nowIso(),
        ];

        $sentToCaller = $this->sendToUserPreferred($call['caller_id'], $call['caller_conn_id'] ?? null, $payload);
        $sentToOtherCalleeSockets = $this->sendToUser($call['callee_id'], [
            'type' => 'call_ended',
            'call_id' => $callId,
            'session_id' => $callId,
            'chat_id' => $call['chat_id'],
            'from' => (int)$call['caller_id'],
            'to' => (int)$call['callee_id'],
            'reason' => $reason,
            'timestamp' => $this->nowIso(),
        ], $from);

        $this->traceSignal('sent_reject', [
            'call_id' => $callId,
            'callee_id' => $calleeId,
            'caller_id' => (int)$call['caller_id'],
            'sent_to_caller' => $sentToCaller,
            'closed_other_callee_sockets' => $sentToOtherCalleeSockets,
            'reason' => $reason,
        ]);
    }

    private function handleCallEnded(ConnectionInterface $from, array $data)
    {
        $endedBy = $this->resolveSenderUserId($from, $data);
        $callId = $this->normalizeCallId($data);
        $reason = trim((string)($data['reason'] ?? 'ended_by_user'));

        $this->traceSignal('recv_call_ended', [
            'conn_id' => $from->resourceId,
            'call_id' => $callId,
            'ended_by' => $endedBy,
            'target_user_id' => $this->normalizeTargetUserId($data),
            'reason' => $reason,
        ]);

        if (!$endedBy || !$callId) {
            return;
        }

        $call = $this->calls[$callId] ?? null;
        if (!$call) {
            $targetUserId = $this->normalizeTargetUserId($data);
            if ($targetUserId) {
                $sent = $this->sendToUser($targetUserId, [
                    'type' => 'call_ended',
                    'call_id' => $callId,
                    'session_id' => $callId,
                    'chat_id' => $this->normalizeChatId($data),
                    'from' => $endedBy,
                    'to' => $targetUserId,
                    'target_user_id' => $targetUserId,
                    'reason' => $reason,
                    'timestamp' => $this->nowIso(),
                ]);

                $this->traceSignal('forward_call_ended_without_call', [
                    'call_id' => $callId,
                    'ended_by' => $endedBy,
                    'target_user_id' => $targetUserId,
                    'sent' => $sent,
                    'reason' => $reason,
                ]);
            }
            return;
        }

        if ($endedBy !== (int)$call['caller_id'] && $endedBy !== (int)$call['callee_id']) {
            return;
        }

        $call = $this->markCallEnded($callId, $reason, $endedBy);
        $peerUserId = $endedBy === (int)$call['caller_id'] ? (int)$call['callee_id'] : (int)$call['caller_id'];
        $peerPreferredConnId = $endedBy === (int)$call['caller_id']
            ? ($call['callee_conn_id'] ?? null)
            : ($call['caller_conn_id'] ?? null);

        $payload = [
            'type' => 'call_ended',
            'call_id' => $callId,
            'session_id' => $callId,
            'chat_id' => $call['chat_id'],
            'from' => $endedBy,
            'to' => $peerUserId,
            'target_user_id' => $peerUserId,
            'reason' => $reason,
            'timestamp' => $this->nowIso(),
        ];

        $sentToPeer = $this->sendToUserPreferred($peerUserId, $peerPreferredConnId, $payload);
        $sentToOwnOtherSockets = $this->sendToUser($endedBy, $payload, $from);

        $this->traceSignal('sent_call_ended', [
            'call_id' => $callId,
            'ended_by' => $endedBy,
            'peer_user_id' => $peerUserId,
            'sent_to_peer' => $sentToPeer,
            'sent_to_own_other_sockets' => $sentToOwnOtherSockets,
            'reason' => $reason,
        ]);

        if (!empty($call['chat_id'])) {
            $this->broadcastToChat($call['chat_id'], [
                'type' => 'call_status',
                'chat_id' => (int)$call['chat_id'],
                'call_id' => $callId,
                'session_id' => $callId,
                'status' => 'ended',
                'ended_by' => $endedBy,
                'reason' => $reason,
                'timestamp' => $this->nowIso(),
            ]);
        }
    }

    private function endCallDueToConnection(ConnectionInterface $conn, array $call, $reason)
    {
        $connId = $conn->resourceId;
        $callId = $call['call_id'];
        $this->markCallEnded($callId, $reason, $conn->userId ?? null);

        if ((string)($call['caller_conn_id'] ?? '') === (string)$connId) {
            $this->sendToUser($call['callee_id'], [
                'type' => 'call_ended',
                'call_id' => $callId,
                'session_id' => $callId,
                'chat_id' => $call['chat_id'],
                'from' => (int)$call['caller_id'],
                'to' => (int)$call['callee_id'],
                'target_user_id' => (int)$call['callee_id'],
                'reason' => $reason,
                'timestamp' => $this->nowIso(),
            ]);
            return;
        }

        if (!empty($call['callee_conn_id']) && (string)$call['callee_conn_id'] === (string)$connId) {
            $this->sendToUserPreferred($call['caller_id'], $call['caller_conn_id'] ?? null, [
                'type' => 'call_ended',
                'call_id' => $callId,
                'session_id' => $callId,
                'chat_id' => $call['chat_id'],
                'from' => (int)$call['callee_id'],
                'to' => (int)$call['caller_id'],
                'target_user_id' => (int)$call['caller_id'],
                'reason' => $reason,
                'timestamp' => $this->nowIso(),
            ]);
            return;
        }

        $userId = isset($conn->userId) ? (int)$conn->userId : 0;
        if ($userId > 0 && (int)$call['callee_id'] === $userId && empty($this->getConnectionsForUser($userId))) {
            $this->sendToUserPreferred($call['caller_id'], $call['caller_conn_id'] ?? null, [
                'type' => 'call_ended',
                'call_id' => $callId,
                'session_id' => $callId,
                'chat_id' => $call['chat_id'],
                'from' => (int)$call['callee_id'],
                'to' => (int)$call['caller_id'],
                'target_user_id' => (int)$call['caller_id'],
                'reason' => $reason,
                'timestamp' => $this->nowIso(),
            ]);
        }
    }

    private function removeConnectionFromUser(ConnectionInterface $conn, $userId = null, $notifyOffline = true)
    {
        $userId = $userId ?: (isset($conn->userId) ? (int)$conn->userId : 0);
        if ($userId < 1) {
            return;
        }

        $connId = $conn->resourceId;
        if (isset($this->userConnections[$userId][$connId])) {
            unset($this->userConnections[$userId][$connId]);
        }

        if (empty($this->userConnections[$userId])) {
            unset($this->userConnections[$userId]);
            if ($notifyOffline) {
                $offlineData = $this->statusManager->setOffline($connId, true);
                if ($offlineData) {
                    $this->notifyUserStatusChange($userId, 'offline', $offlineData);
                }
            }
        }
    }

    private function removeConnectionFromSessions(ConnectionInterface $conn, $userId = null)
    {
        $connId = $conn->resourceId;
        $processedChats = [];
        $joinedChats = is_array($conn->joinedChats ?? null) ? array_keys($conn->joinedChats) : [];

        foreach ($joinedChats as $chatId) {
            if (!empty($this->sessions[$chatId][$connId])) {
                unset($this->sessions[$chatId][$connId]);
                if (empty($this->sessions[$chatId])) {
                    unset($this->sessions[$chatId]);
                }
                if ($userId && !$this->isUserPresentInChat($chatId, $userId, $connId)) {
                    $this->notifyUserLeftChat($chatId, $userId);
                }
            }
            $processedChats[$chatId] = true;
        }

        foreach ($this->sessions as $chatId => $connections) {
            if (isset($processedChats[$chatId])) {
                continue;
            }
            if (!isset($connections[$connId])) {
                continue;
            }
            unset($this->sessions[$chatId][$connId]);
            if (empty($this->sessions[$chatId])) {
                unset($this->sessions[$chatId]);
            }
            if ($userId && !$this->isUserPresentInChat($chatId, $userId, $connId)) {
                $this->notifyUserLeftChat($chatId, $userId);
            }
        }

        $conn->joinedChats = [];
    }

    private function cleanupConnection(ConnectionInterface $conn, $reason = 'closed')
    {
        if (!$this->clients->contains($conn)) {
            return;
        }

        $userId = isset($conn->userId) ? (int)$conn->userId : 0;
        $this->cancelHeartbeatTimer($conn);
        $this->removeConnectionFromSessions($conn, $userId);
        $this->removeConnectionFromUser($conn, $userId, true);

        foreach ($this->calls as $callId => $call) {
            if (($call['status'] ?? '') === 'ended') {
                continue;
            }

            $callerConnId = $call['caller_conn_id'] ?? null;
            $calleeConnId = $call['callee_conn_id'] ?? null;

            if ((string)$callerConnId === (string)$conn->resourceId) {
                $this->endCallDueToConnection($conn, $call, 'caller_disconnected');
                continue;
            }

            if ($calleeConnId !== null && (string)$calleeConnId === (string)$conn->resourceId) {
                $this->endCallDueToConnection($conn, $call, 'callee_disconnected');
                continue;
            }

            if ($userId > 0 && (int)$call['callee_id'] === $userId && ($call['status'] ?? '') === 'ringing' && empty($this->getConnectionsForUser($userId))) {
                $this->endCallDueToConnection($conn, $call, 'recipient_offline');
            }
        }

        $this->clients->detach($conn);
        try {
            $conn->close();
        } catch (\Throwable $e) {
        }

        $this->logToFile("🔌 Conexión #{$conn->resourceId} limpiada ({$reason})");
    }

    public function checkDatabaseNotifications()
    {
        if (!$this->chatModel) {
            return;
        }

        try {
            $sql = "SELECT id, chat_id, user_id, message_type, message_data, created_at
                FROM websocket_notifications
                WHERE status = 'pending'
                AND processed_at IS NULL
                ORDER BY created_at ASC
                LIMIT 10";

            $notifications = $this->chatModel->query($sql);
            if (empty($notifications)) {
                return;
            }

            foreach ($notifications as $notification) {
                $this->processNotification($notification);
            }
        } catch (\Throwable $e) {
            $this->logToFile("❌ Error en checkDatabaseNotifications: {$e->getMessage()}");
        }
    }

    private function processNotification(array $notification)
    {
        try {
            $messageData = json_decode($notification['message_data'] ?? '{}', true);
            if (!is_array($messageData)) {
                $this->markAsProcessed($notification['id'], 'error');
                return;
            }

            $payload = [
                'type' => $notification['message_type'] ?? 'chat_message',
                'chat_id' => (int)($messageData['chat_id'] ?? $notification['chat_id']),
                'user_id' => (int)($messageData['user_id'] ?? $notification['user_id']),
                'contenido' => $messageData['contenido'] ?? $messageData['file_original_name'] ?? 'Archivo',
                'tipo' => $messageData['tipo'] ?? (($notification['message_type'] ?? '') === 'image_upload' ? 'imagen' : 'archivo'),
                'timestamp' => $notification['created_at'] ?? $this->nowIso(),
                'message_id' => $notification['id'],
                'file_url' => $messageData['file_url'] ?? '',
                'file_original_name' => $messageData['file_original_name'] ?? '',
                'file_size' => $messageData['file_size'] ?? 0,
                'file_mime_type' => $messageData['file_mime_type'] ?? '',
                'status' => 'delivered',
            ];

            $this->broadcastToChat($payload['chat_id'], $payload);
            $this->notifyChatListUpdate($payload['chat_id'], $payload);
            $this->markAsProcessed($notification['id'], 'processed');
        } catch (\Throwable $e) {
            $this->logToFile("❌ Error procesando notificación {$notification['id']}: {$e->getMessage()}");
            $this->markAsProcessed($notification['id'], 'error');
        }
    }

    private function markAsProcessed($notificationId, $status = 'processed')
    {
        if (!$this->chatModel) {
            return false;
        }

        try {
            $sql = "UPDATE websocket_notifications
                SET status = ?, processed_at = NOW()
                WHERE id = ?";

            $this->chatModel->query($sql, [$status, $notificationId]);
            return true;
        } catch (\Throwable $e) {
            $this->logToFile("❌ Error marcando notificación {$notificationId}: {$e->getMessage()}");
            return false;
        }
    }

    private function cleanupOrphans()
    {
        foreach ($this->userConnections as $userId => $connections) {
            foreach ($connections as $connId => $conn) {
                if (!$conn instanceof ConnectionInterface || !$this->clients->contains($conn)) {
                    unset($this->userConnections[$userId][$connId]);
                }
            }
            if (empty($this->userConnections[$userId])) {
                unset($this->userConnections[$userId]);
            }
        }

        foreach ($this->sessions as $chatId => $connections) {
            foreach ($connections as $connId => $conn) {
                if (!$conn instanceof ConnectionInterface || !$this->clients->contains($conn)) {
                    unset($this->sessions[$chatId][$connId]);
                }
            }
            if (empty($this->sessions[$chatId])) {
                unset($this->sessions[$chatId]);
            }
        }
    }

    private function cleanupStaleCalls()
    {
        $now = time();

        foreach ($this->calls as $callId => $call) {
            $status = $call['status'] ?? 'ended';
            $updatedAt = (int)($call['updated_at'] ?? $now);

            if ($status === 'ended' && ($now - $updatedAt) > 300) {
                unset($this->calls[$callId]);
                continue;
            }

            if ($status === 'ringing' && ($now - $updatedAt) > 60) {
                $call = $this->markCallEnded($callId, 'timeout', $call['caller_id'] ?? null);
                if ($call) {
                    $this->sendToUserPreferred($call['caller_id'], $call['caller_conn_id'] ?? null, [
                        'type' => 'call_ended',
                        'call_id' => $callId,
                        'session_id' => $callId,
                        'chat_id' => $call['chat_id'],
                        'from' => (int)$call['callee_id'],
                        'to' => (int)$call['caller_id'],
                        'target_user_id' => (int)$call['caller_id'],
                        'reason' => 'timeout',
                        'timestamp' => $this->nowIso(),
                    ]);
                    $this->sendToUser($call['callee_id'], [
                        'type' => 'call_ended',
                        'call_id' => $callId,
                        'session_id' => $callId,
                        'chat_id' => $call['chat_id'],
                        'from' => (int)$call['caller_id'],
                        'to' => (int)$call['callee_id'],
                        'target_user_id' => (int)$call['callee_id'],
                        'reason' => 'timeout',
                        'timestamp' => $this->nowIso(),
                    ]);
                }
            }
        }
    }

    public function periodicCleanup()
    {
        $this->statusManager->cleanupStaleConnections();
        $this->cleanupOrphans();
        $this->cleanupStaleCalls();
    }
}
