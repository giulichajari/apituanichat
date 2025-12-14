<?php
// ws-server.php - VERSIÓN CORREGIDA CON CHATMODEL INTEGRADO


// ===================== CONFIGURACIÓN DEBUG =====================
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-error.log');

echo "🔧 DEBUG activado\n";
echo "📂 Directorio actual: " . __DIR__ . "\n";

// ===================== CARGAR VENDOR =====================
$autoloadPath = __DIR__ . '/vendor/autoload.php';

if (!file_exists($autoloadPath)) {
    die("❌ ERROR: vendor/autoload.php no encontrado\n");
}

require $autoloadPath;
echo "✅ Vendor autoload cargado\n";

// ===================== CARGAR CHATMODEL =====================
// Asegúrate de que esta ruta sea correcta
$chatModelPath = __DIR__ . '/App/Models/ChatModel.php';
if (!file_exists($chatModelPath)) {
    echo "⚠️ ChatModel.php no encontrado en: $chatModelPath\n";
    echo "📂 Buscando en otras ubicaciones...\n";

    // Intentar otras ubicaciones comunes
    $possiblePaths = [
        __DIR__ . '/../app/Models/ChatModel.php',
        __DIR__ . '/../../app/Models/ChatModel.php',
        __DIR__ . '/../../../app/Models/ChatModel.php',
        getcwd() . '/app/Models/ChatModel.php'
    ];

    $found = false;
    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            $chatModelPath = $path;
            $found = true;
            break;
        }
    }

    if (!$found) {
        echo "❌ ChatModel.php no encontrado en ninguna ubicación\n";
    } else {
        echo "✅ ChatModel encontrado en: $chatModelPath\n";
    }
}

if (file_exists($chatModelPath)) {
    require_once $chatModelPath;
    echo "✅ ChatModel cargado\n";
} else {
    echo "⚠️ Continuando sin ChatModel\n";
}

// ===================== CONFIGURACIÓN REDIS =====================
use Predis\Client as RedisClient;
use Ratchet\ConnectionInterface;

class UserStatusManager
{
    private $redis;
    private $expireTime = 60;

    public function __construct()
    {
        try {
            $this->redis = new RedisClient([
                'scheme' => 'tcp',
                'host'   => '127.0.0.1',
                'port'   => 6379,
                'password' => null,
                'database' => 0,
                'timeout' => 2.5
            ]);

            $this->redis->ping();
            echo "✅ Redis conectado exitosamente\n";
        } catch (Exception $e) {
            echo "❌ Error Redis: " . $e->getMessage() . "\n";
            $this->redis = null;
        }
    }
// En la clase UserStatusManager, agrega este método:
    /**
     * Obtener estado de múltiples usuarios
     */
    public function getUsersStatus($userIds)
    {
        if (!$this->redis) return [];

        $results = [];
        foreach ($userIds as $userId) {
            $results[$userId] = $this->getUserStatus($userId);
        }
        return $results;
    }
    public function setOnline($userId, $connectionId, $userData = [])
    {
        if (!$this->redis) return false;

        $key = "user:online:{$userId}";
        $connectionKey = "user:connection:{$connectionId}";

        $userData = array_merge([
            'user_id' => $userId,
            'connection_id' => $connectionId,
            'last_seen' => time(),
            'status' => 'online',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ], $userData);

        $this->redis->hmset($key, $userData);
        $this->redis->expire($key, $this->expireTime);
        $this->redis->set($connectionKey, $userId);
        $this->redis->expire($connectionKey, $this->expireTime);
        $this->redis->zadd('users:online', time(), $userId);

        echo "✅ Usuario {$userId} marcado como ONLINE\n";
        return true;
    }

    public function updateActivity($userId)
    {
        if (!$this->redis) return false;

        $key = "user:online:{$userId}";
        if ($this->redis->exists($key)) {
            $this->redis->hset($key, 'last_seen', time());
            $this->redis->expire($key, $this->expireTime);
            $this->redis->zadd('users:online', time(), $userId);
            return true;
        }
        return false;
    }

    public function setOffline($connectionId, $notify = true)
    {
        if (!$this->redis) return false;

        $connectionKey = "user:connection:{$connectionId}";
        $userId = $this->redis->get($connectionKey);

        if (!$userId) return false;

        $userKey = "user:online:{$userId}";
        $userData = $this->redis->hgetall($userKey);

        $this->redis->del($userKey);
        $this->redis->del($connectionKey);
        $this->redis->zrem('users:online', $userId);
        $this->redis->zadd('users:offline:history', time(), $userId);
        $this->redis->expire('users:offline:history', 3600);

        echo "✅ Usuario {$userId} marcado como OFFLINE\n";

        if ($notify && !empty($userData)) {
            return [
                'user_id' => $userId,
                'connection_id' => $connectionId,
                'last_seen' => $userData['last_seen'] ?? time(),
                'notified_at' => time()
            ];
        }

        return ['user_id' => $userId];
    }

    public function isOnline($userId)
    {
        if (!$this->redis) return false;
        return $this->redis->exists("user:online:{$userId}");
    }

    public function getOnlineUsers($limit = 100)
    {
        if (!$this->redis) return [];

        $userIds = $this->redis->zrevrange('users:online', 0, $limit - 1);
        $users = [];

        foreach ($userIds as $userId) {
            $key = "user:online:{$userId}";
            $userData = $this->redis->hgetall($key);
            if ($userData) {
                $users[] = $userData;
            }
        }

        return $users;
    }

    public function getUserStatus($userId)
    {
        if (!$this->redis) return ['status' => 'offline'];

        $key = "user:online:{$userId}";
        if ($this->redis->exists($key)) {
            $data = $this->redis->hgetall($key);
            $data['status'] = 'online';
            $data['online_since'] = $data['last_seen'] ?? time();
            return $data;
        }

        $history = $this->redis->zscore('users:offline:history', $userId);
        if ($history) {
            return [
                'status' => 'offline',
                'last_seen' => (int)$history,
                'user_id' => $userId
            ];
        }

        return ['status' => 'offline', 'user_id' => $userId];
    }

    public function cleanupStaleConnections()
    {
        if (!$this->redis) return 0;

        $cleaned = 0;
        $onlineUsers = $this->getOnlineUsers(1000);
        $now = time();

        foreach ($onlineUsers as $user) {
            $lastSeen = $user['last_seen'] ?? 0;
            if (($now - $lastSeen) > $this->expireTime) {
                $this->setOffline($user['connection_id'] ?? '', false);
                $cleaned++;
            }
        }

        if ($cleaned > 0) {
            echo "🧹 Limpiadas {$cleaned} conexiones inactivas\n";
        }

        return $cleaned;
    }

    public function getStats()
    {
        if (!$this->redis) return [];

        return [
            'online_users' => $this->redis->zcard('users:online'),
            'active_connections' => count($this->redis->keys("user:connection:*"))
        ];
    }
}

// ===================== CLASE DEL SERVIDOR MEJORADA =====================
class SignalServer implements \Ratchet\MessageComponentInterface
{
    protected $clients;
    protected $sessions = [];
    protected $userConnections = []; // user_id => [connection_id => connection]
    protected $statusManager;
    protected $userTimers = [];
    protected $chatModel;

    // NUEVO: Para búsqueda rápida
    private $userIdByConnectionId = []; // connection_id => user_id
    private $connectionById = []; // connection_id => connection

    public function __construct()
    {
        $this->clients = new \SplObjectStorage();
        $this->statusManager = new UserStatusManager();
        $this->initializeChatModel();
        echo "🚀 SignalServer inicializado\n";
    }
    private function getUserId(ConnectionInterface $conn)
    {
        $connId = $conn->resourceId;

        // Opción 1: Buscar en cache rápido
        if (isset($this->userIdByConnectionId[$connId])) {
            return $this->userIdByConnectionId[$connId];
        }

        // Opción 2: Buscar en propiedades de la conexión
        if (isset($conn->userId)) {
            $userId = (int)$conn->userId;
            $this->userIdByConnectionId[$connId] = $userId;
            return $userId;
        }

        // Opción 3: Buscar en userConnections
        foreach ($this->userConnections as $userId => $connections) {
            if (isset($connections[$connId])) {
                $this->userIdByConnectionId[$connId] = (int)$userId;
                $conn->userId = (int)$userId; // Cachear para futuro
                return (int)$userId;
            }
        }

        return null;
    }

    // En SignalServer class
    private function notifyNewMessage($chatId, $messageData, $senderId = null)
    {
        $message = [
            'type' => 'new_message',
            'chat_id' => $chatId,
            'message' => $messageData,
            'sender_id' => $senderId,
            'timestamp' => time(),
            'action' => 'message_received'
        ];

        // Enviar a todos en el chat
        $this->broadcastToChat($chatId, $message);

        // También enviar notificación de actualización de lista de chats
        $this->notifyChatListUpdate($chatId, $messageData);
    }

    private function notifyChatListUpdate($chatId, $messageData)
    {
        // Preparar datos de actualización del chat
        $updateData = [
            'type' => 'chat_updated',
            'chat_id' => $chatId,
            'last_message' => $messageData['contenido'] ?? '',
            'last_message_time' => date('c'),
            'unread_count' => 1, // Se incrementará en el cliente
            'sender_id' => $messageData['user_id'] ?? null,
            'action' => 'bump_to_top'
        ];

        // Enviar a todos los usuarios que estén en este chat
        if (isset($this->sessions[$chatId])) {
            foreach ($this->sessions[$chatId] as $client) {
                try {
                    $client->send(json_encode($updateData));
                } catch (\Exception $e) {
                    echo "❌ Error enviando actualización de chat: {$e->getMessage()}\n";
                }
            }
        }
    }

    private function notifyUnreadCount($chatId, $userId, $count)
    {
        $message = [
            'type' => 'unread_count_update',
            'chat_id' => $chatId,
            'user_id' => $userId,
            'unread_count' => $count,
            'timestamp' => time()
        ];

        // Enviar al usuario específico
        if (isset($this->userConnections[$userId])) {
            foreach ($this->userConnections[$userId] as $client) {
                try {
                    $client->send(json_encode($message));
                } catch (\Exception $e) {
                    echo "❌ Error enviando conteo no leído: {$e->getMessage()}\n";
                }
            }
        }
    }
    private function initializeChatModel()
    {
        try {
            // Verificar si la clase existe
            if (!class_exists('App\Models\ChatModel')) {
                echo "❌ Clase ChatModel no encontrada\n";
                $this->chatModel = null;
                return;
            }

            // Intentar crear instancia
            $this->chatModel = new \App\Models\ChatModel();
            echo "✅ ChatModel inicializado correctamente\n";
        } catch (Exception $e) {
            echo "❌ Error inicializando ChatModel: " . $e->getMessage() . "\n";
            $this->chatModel = null;
        }
    }

    public function onOpen(\Ratchet\ConnectionInterface $conn)
    {
        $userId = $this->getUserId($conn);

        $this->users[$userId] = $conn;
        $this->clients->attach($conn);
        echo date('H:i:s') . " 🔗 Conexión #{$conn->resourceId} abierta\n";

        $conn->send(json_encode([
            'type' => 'welcome',
            'message' => 'WebSocket conectado',
            'connection_id' => $conn->resourceId,
            'server_time' => date('Y-m-d H:i:s')
        ]));
    }

    public function onClose(\Ratchet\ConnectionInterface $conn)
    {
        $connId = $conn->resourceId;
        $userId = $this->getUserId($conn);

        echo date('H:i:s') . " ❌ Conexión #{$connId} cerrada";
        if ($userId) {
            echo " (usuario {$userId})";
        }
        echo "\n";

        // Limpiar timers
        if (isset($this->userTimers[$connId])) {
            $timer = $this->userTimers[$connId];
            if ($timer && $timer instanceof \React\EventLoop\TimerInterface) {
                \React\EventLoop\Loop::cancelTimer($timer);
            }
            unset($this->userTimers[$connId]);
        }

        // Remover de sesiones de chat
        foreach ($this->sessions as $chatId => $connections) {
            if (isset($connections[$connId])) {
                unset($this->sessions[$chatId][$connId]);

                if ($userId) {
                    $this->notifyUserLeftChat($chatId, $userId);
                }

                echo "👋 Removido de chat {$chatId}\n";

                // Si no hay más conexiones en este chat, limpiar
                if (empty($this->sessions[$chatId])) {
                    unset($this->sessions[$chatId]);
                }
            }
        }

        // Marcar como offline
        if ($userId) {
            // Remover de userConnections
            if (isset($this->userConnections[$userId][$connId])) {
                unset($this->userConnections[$userId][$connId]);

                // Si no quedan más conexiones para este usuario, limpiar
                if (empty($this->userConnections[$userId])) {
                    unset($this->userConnections[$userId]);

                    // Marcar como offline en Redis
                    $offlineData = $this->statusManager->setOffline($connId, true);

                    if ($offlineData) {
                        $this->notifyUserStatusChange($offlineData['user_id'], 'offline', $offlineData);
                    }

                    echo "📢 Usuario {$userId} completamente desconectado\n";
                } else {
                    echo "ℹ️ Usuario {$userId} aún tiene otras conexiones activas\n";
                }
            }
        }

        // Limpiar estructuras de búsqueda rápida
        unset($this->userIdByConnectionId[$connId]);
        unset($this->connectionById[$connId]);

        // Remover del almacenamiento principal
        $this->clients->detach($conn);
    }

    public function onError(\Ratchet\ConnectionInterface $conn, \Exception $e)
    {
        echo date('H:i:s') . " ⚠️ Error #{$conn->resourceId}: {$e->getMessage()}\n";

        if (isset($this->userTimers[$conn->resourceId])) {
            $timer = $this->userTimers[$conn->resourceId];
            if ($timer && $timer instanceof \React\EventLoop\TimerInterface) {
                \React\EventLoop\Loop::cancelTimer($timer);
            }
            unset($this->userTimers[$conn->resourceId]);
        }

        $conn->close();
    }

    private function logToFile($message)
    {
        $logFile = __DIR__ . '/websocket_debug.log';
        $timestamp = date('Y-m-d H:i:s');
        $formattedMessage = "[$timestamp] " . $message . "\n";
        file_put_contents($logFile, $formattedMessage, FILE_APPEND | LOCK_EX);

        if (php_sapi_name() === 'cli') {
            echo $formattedMessage;
        }
    }
    public function onMessage(\Ratchet\ConnectionInterface $from, $msg)
    {
        $connId = $from->resourceId;
        echo date('H:i:s') . " 📨 #{$connId} → " . (is_string($msg) ? substr($msg, 0, 200) : "[BINARIO " . strlen($msg) . " bytes]") . "\n";

        try {
            // 1. Si es string, intentar parsear como JSON
            if (is_string($msg)) {
                // DEBUG: Mostrar el mensaje completo
                echo "🔍 Mensaje string recibido (primeros 500 chars):\n" . substr($msg, 0, 500) . "\n";

                $data = json_decode($msg, true);

                if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                    // No es JSON válido, podría ser audio binario
                    echo "🎵 No es JSON, asumiendo audio binario: " . strlen($msg) . " bytes\n";

                    foreach ($this->clients as $client) {
                        if ($from !== $client) {
                            $client->send($msg);
                        }
                    }
                    return;
                }

                // ✅ Es JSON válido
                echo "✅ JSON válido recibido\n";

                if (!isset($data['type'])) {
                    echo "❌ JSON sin tipo de mensaje\n";
                    return;
                }

                $msgType = $data['type'];
                echo "🎯🎯🎯 Tipo de mensaje: {$msgType} 🎯🎯🎯\n";
                echo "📦 Datos completos:\n";
                print_r($data);

                // 2. Procesar según el tipo
                switch ($msgType) {
                    // ========== IDENTIFICACIÓN ==========
                    case 'identify':
                        echo "🆔 Manejando mensaje identify\n";
                        if (isset($data['user_id'])) {
                            $userId = (int)$data['user_id'];
                            $this->connectionUsers[$connId] = $userId;
                            $this->users[$userId] = $from;
                            echo "✅ Usuario {$userId} identificado en conexión #{$connId}\n";
                        }
                        return;

                    case 'auth':
                        echo "🔐 Manejando mensaje auth\n";
                        $this->handleAuth($from, $data);
                        return;

                        // ========== CHAT BÁSICO ==========
                    case 'ping':
                        $this->handlePing($from);
                        break;

                    case 'heartbeat':
                        $this->handleHeartbeat($from, $data);
                        break;

                    case 'join_chat':
                        $this->handleJoinChat($from, $data);
                        break;

                    case 'chat_message':
                        $this->handleChatMessage($from, $data);
                        break;

                    // ========== ARCHIVOS ==========
                    case 'file_upload':
                    case 'image_upload':
                        $this->handleFileUpload($from, $data);
                        break;

                    case 'mark_as_read':
                        $this->handleMarkAsRead($from, $data);
                        break;

                    case 'typing':
                        $this->handleTyping($from, $data);
                        break;

                    // ========== ESTADOS ==========
                    case 'get_online_users':
                        $this->handleGetOnlineUsers($from, $data);
                        break;

                    case 'get_user_status':
                        $this->handleGetUserStatus($from, $data);
                        break;

                    // ========== LLAMADAS DE VOZ ==========
                    case 'init_call':
                        echo "📞📞📞📞📞 INIT_CALL RECIBIDO 📞📞📞📞📞\n";
                        $this->handleInitCall($from, $data);
                        break;

                    case 'call_request':
                        echo "📞📞📞📞📞 CALL_REQUEST RECIBIDO 📞📞📞📞📞\n";
                        $this->handleCallRequest($from, $data);
                        break;

                    case 'call_offer':
                        echo "📞📞📞📞📞 CALL_OFFER RECIBIDO 📞📞📞📞📞\n";
                        $this->handleCallOffer($from, $data);
                        break;

                    case 'call_answer':
                        echo "📞📞📞📞📞 CALL_ANSWER RECIBIDO 📞📞📞📞📞\n";
                        $this->handleCallAnswer($from, $data);
                        break;

                    case 'call_accepted':
                        echo "📞📞📞📞📞 CALL_ACCEPTED RECIBIDO 📞📞📞📞📞\n";
                        $this->handleCallAccepted($from, $data);
                        break;

                    case 'call_candidate':
                        echo "📞📞📞📞📞 CALL_CANDIDATE RECIBIDO 📞📞📞📞📞\n";
                        $this->handleCallCandidate($from, $data);
                        break;

                    case 'call_ended':
                        echo "📞📞📞📞📞 CALL_ENDED RECIBIDO 📞📞📞📞📞\n";
                        $this->handleCallEnded($from, $data);
                        break;

                    case 'call_reject':
                    case 'call_rejected':
                        echo "📞📞📞📞📞 CALL_REJECT RECIBIDO 📞📞📞📞📞\n";
                        $this->handleCallReject($from, $data);
                        break;

                    default:
                        echo "⚠️⚠️⚠️⚠️⚠️ TIPO DESCONOCIDO: {$msgType} ⚠️⚠️⚠️⚠️⚠️\n";
                        $from->send(json_encode([
                            'type' => 'error',
                            'message' => 'Tipo no soportado: ' . $msgType
                        ]));
                }
            } else {
                // Mensaje binario (audio)
                echo "🎵 Audio binario recibido: " . strlen($msg) . " bytes\n";

                foreach ($this->clients as $client) {
                    if ($from !== $client) {
                        $client->send($msg);
                    }
                }
            }
        } catch (\Exception $e) {
            echo "❌❌❌ ERROR en onMessage: " . $e->getMessage() . "\n";
            echo "📂 Archivo: " . $e->getFile() . ":" . $e->getLine() . "\n";
            echo "🧵 Trace:\n" . $e->getTraceAsString() . "\n";
        }
    }
    /**
     * Manejar aceptación de llamada
     */
    private function handleCallAccepted($from, $data)
    {
        $userId = $this->getUserIdFromConnection($from);
        $toUserId = $data['to'] ?? null;
        $sessionId = $data['session_id'] ?? null;
        $chatId = $data['chat_id'] ?? null;

        if (!$userId || !$toUserId || !$sessionId) {
            echo "❌ Datos incompletos en call_accepted\n";
            return;
        }

        echo "✅ Llamada aceptada por {$userId} para sesión {$sessionId}\n";

        $toConnection = $this->findConnectionByUserId($toUserId);

        if ($toConnection) {
            $toConnection->send(json_encode([
                'type' => 'call_accepted',
                'session_id' => $sessionId,
                'from' => $userId,
                'to' => $toUserId,
                'chat_id' => $chatId,
                'timestamp' => date('Y-m-d H:i:s')
            ]));

            echo "📤 call_accepted enviado a {$toUserId}\n";
        } else {
            echo "❌ Destinatario {$toUserId} no encontrado\n";
        }
    }
    /**
     * Manejar indicador de typing
     */
    private function handleTyping($from, $data)
    {
        $userId = $this->getUserIdFromConnection($from);
        $chatId = $data['chat_id'] ?? null;
        $isTyping = $data['isTyping'] ?? false;

        if (!$userId || !$chatId) {
            echo "❌ Datos incompletos en typing\n";
            return;
        }

        // Enviar a todos en el chat excepto al remitente
        if (isset($this->sessions[$chatId])) {
            foreach ($this->sessions[$chatId] as $client) {
                if ($client !== $from) {
                    try {
                        $client->send(json_encode([
                            'type' => 'typing',
                            'chat_id' => $chatId,
                            'user_id' => $userId,
                            'isTyping' => $isTyping,
                            'timestamp' => date('Y-m-d H:i:s')
                        ]));
                    } catch (\Exception $e) {
                        echo "❌ Error enviando typing: {$e->getMessage()}\n";
                    }
                }
            }
        }

        echo "⌨️ Typing de {$userId} en chat {$chatId}: " . ($isTyping ? 'SÍ' : 'NO') . "\n";
    }
    private function handleCallRequest($from, $data)
    {
        $userId = $this->getUserIdFromConnection($from);
        $toUserId = $data['to'] ?? null;
        $chatId = $data['chat_id'] ?? null;
        $sessionId = $data['session_id'] ?? null;

        if (!$userId || !$toUserId || !$chatId || !$sessionId) return;

        $toConnection = $this->findConnectionByUserId($toUserId);

        if ($toConnection) {
            $toConnection->send(json_encode([
                'type' => 'incoming_call',
                'session_id' => $sessionId,
                'from' => $userId,
                'to' => $toUserId,
                'chat_id' => $chatId,
                'caller_name' => $data['caller_name'] ?? 'Usuario',
                'timestamp' => date('Y-m-d H:i:s')
            ]));

            echo "📞 Solicitud de llamada de {$userId} a {$toUserId}\n";
        } else {
            // Usuario offline
            $from->send(json_encode([
                'type' => 'call_ended',
                'session_id' => $sessionId,
                'reason' => 'user_offline',
                'message' => 'Usuario no disponible'
            ]));
        }
    }
    /**
     * 🔹 Helper para detectar si un string es JSON válido
     */
    private function isJson($string): bool
    {
        if (!is_string($string)) return false;
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }



    /**
     * Maneja oferta de WebRTC
     */
    private function handleCallOffer($from, $data)
    {
        $userId = $this->getUserIdFromConnection($from);
        $toUserId = $data['to'] ?? null;

        if (!$userId || !$toUserId) {
            return;
        }

        // Buscar el socket del receptor
        $toConnection = $this->findConnectionByUserId($toUserId);

        if ($toConnection) {

            // 🔥 Reenviar TODOS los atributos del mensaje original
            // + asegurar que 'from' y 'timestamp' sean correctos
            $data['from'] = $userId;
            $data['timestamp'] = date('Y-m-d H:i:s');

            $toConnection->send(json_encode($data));

            echo "📞 Oferta WebRTC enviada de {$userId} a {$toUserId}\n";
        }
    }


    /**
     * Maneja respuesta de WebRTC
     */
    private function handleCallAnswer($from, $data)
    {
        $userId = $this->getUserIdFromConnection($from);
        $toUserId = $data['to'] ?? null;
        $sessionId = $data['session_id'] ?? null;
        $sdp = $data['sdp'] ?? null;

        if (!$userId || !$toUserId || !$sessionId || !$sdp) {
            return;
        }

        $toConnection = $this->findConnectionByUserId($toUserId);

        if ($toConnection) {
            $toConnection->send(json_encode([
                'type' => 'call_answer',
                'session_id' => $sessionId,
                'from' => $userId,
                'to' => $toUserId,
                'sdp' => $sdp,
                'timestamp' => date('Y-m-d H:i:s')
            ]));

            echo "📞 Respuesta WebRTC enviada de {$userId} a {$toUserId}\n";
        }
    }

    /**
     * Maneja candidatos ICE
     */
    private function handleCallCandidate($from, $data)
    {
        $userId = $this->getUserIdFromConnection($from);
        $toUserId = $data['to'] ?? null;
        $sessionId = $data['session_id'] ?? null;
        $candidate = $data['candidate'] ?? null;

        if (!$userId || !$toUserId || !$sessionId || !$candidate) {
            return;
        }

        $toConnection = $this->findConnectionByUserId($toUserId);

        if ($toConnection) {
            $toConnection->send(json_encode([
                'type' => 'call_candidate',
                'session_id' => $sessionId,
                'from' => $userId,
                'to' => $toUserId,
                'candidate' => $candidate,
                'timestamp' => date('Y-m-d H:i:s')
            ]));

            echo "📞 Candidato ICE enviado de {$userId} a {$toUserId}\n";
        }
    }

    /**
     * Maneja fin de llamada
     */
    private function handleCallEnded($from, $data)
    {
        $userId = $this->getUserIdFromConnection($from);
        $toUserId = $data['to'] ?? null;
        $sessionId = $data['session_id'] ?? null;
        $reason = $data['reason'] ?? 'ended_by_user';

        if (!$userId || !$sessionId) {
            return;
        }

        // Si hay destinatario, notificarle
        if ($toUserId) {
            $toConnection = $this->findConnectionByUserId($toUserId);
            if ($toConnection) {
                $toConnection->send(json_encode([
                    'type' => 'call_ended',
                    'session_id' => $sessionId,
                    'from' => $userId,
                    'reason' => $reason,
                    'timestamp' => date('Y-m-d H:i:s')
                ]));
            }
        }

        // También notificar a todos en el chat
        $chatId = $data['chat_id'] ?? null;
        if ($chatId) {
            $this->broadcastToChat($chatId, [
                'type' => 'call_status',
                'session_id' => $sessionId,
                'status' => 'ended',
                'ended_by' => $userId,
                'reason' => $reason,
                'timestamp' => date('Y-m-d H:i:s')
            ], $from);
        }

        echo "📞 Llamada {$sessionId} terminada por {$userId}\n";
    }

    /**
     * Maneja rechazo de llamada
     */
    private function handleCallReject($from, $data)
    {
        $userId = $this->getUserIdFromConnection($from);
        $toUserId = $data['to'] ?? null;
        $sessionId = $data['session_id'] ?? null;
        $reason = $data['reason'] ?? 'rejected';

        if (!$userId || !$toUserId || !$sessionId) {
            return;
        }

        $toConnection = $this->findConnectionByUserId($toUserId);

        if ($toConnection) {
            $toConnection->send(json_encode([
                'type' => 'call_rejected',
                'session_id' => $sessionId,
                'from' => $userId,
                'reason' => $reason,
                'timestamp' => date('Y-m-d H:i:s')
            ]));

            echo "📞 Llamada {$sessionId} rechazada por {$userId}\n";
        }
    }


    // ===================== HANDLERS PRINCIPALES =====================


    // En tu ws-server.php, línea 793 y alrededor

    private function findConnectionByUserId($userId)
    {
        $userId = (int)$userId;

        echo "🔍 Buscando conexiones para usuario {$userId}\n";

        if (!isset($this->userConnections[$userId])) {
            echo "❌ Usuario {$userId} no tiene conexiones registradas\n";

            // DEBUG: Mostrar usuarios conectados
            echo "👥 Usuarios actualmente conectados:\n";
            foreach ($this->userConnections as $uid => $connections) {
                if (!empty($connections)) {
                    echo "  - Usuario {$uid}: " . count($connections) . " conexión(es)\n";
                }
            }

            return null;
        }

        $connections = $this->userConnections[$userId];

        if (empty($connections)) {
            echo "⚠️ Usuario {$userId} tiene array de conexiones pero está vacío\n";
            return null;
        }

        // Tomar la primera conexión activa
        foreach ($connections as $connId => $connection) {
            // Verificar que la conexión aún esté activa
            if ($connection instanceof ConnectionInterface) {
                echo "✅ Conexión encontrada: #{$connId} para usuario {$userId}\n";
                return $connection;
            } else {
                echo "⚠️ Conexión #{$connId} para usuario {$userId} no es válida, limpiando...\n";
                unset($this->userConnections[$userId][$connId]);
                unset($this->userIdByConnectionId[$connId]);
                unset($this->connectionById[$connId]);
            }
        }

        echo "❌ No se encontraron conexiones válidas para usuario {$userId}\n";
        return null;
    }



    private function handleInitCall($from, $data)
    {
        echo "\n📞 ========== INICIANDO LLAMADA ==========\n";
        echo "📦 Datos recibidos: " . json_encode($data) . "\n";

        $userIdFromMessage = isset($data['from']) ? (int)$data['from'] : null;
        $userIdFromConnection = $this->getUserIdFromConnection($from);
        $userId = $userIdFromMessage ?? $userIdFromConnection;

        if (!$userId) {
            echo "❌ ERROR: No se pudo determinar userId\n";
            $from->send(json_encode([
                'type' => 'call_error',
                'message' => 'No se pudo identificar al usuario',
                'session_id' => $data['session_id'] ?? null
            ]));
            return;
        }

        $sessionId = $data['session_id'] ?? uniqid('call_', true);
        $toUserId = isset($data['to']) ? (int)$data['to'] : null;
        $chatId = $data['chat_id'] ?? null;
        $callerName = $data['caller_name'] ?? 'Usuario';
        $sdpOffer = $data['sdp'] ?? null; // <-- Oferta SDP del llamante

        if (!$userId || !$toUserId || !$chatId || !$sdpOffer) {
            echo "❌ Datos incompletos para iniciar llamada\n";
            $from->send(json_encode([
                'type' => 'call_error',
                'message' => 'Datos incompletos para iniciar llamada',
                'session_id' => $sessionId,
                'missing' => [
                    'from' => !$userId,
                    'to' => !$toUserId,
                    'chat_id' => !$chatId,
                    'sdp' => !$sdpOffer
                ]
            ]));
            return;
        }

        // Buscar conexión del destinatario
        $toConnection = $this->findConnectionByUserId($toUserId);

        if ($toConnection) {
            echo "✅ Destinatario {$toUserId} encontrado (conexión #{$toConnection->resourceId})\n";

            // ⭐⭐ CORRECCIÓN: INCLUIR EL SDP EN EL MENSAJE incoming_call ⭐⭐
            $incomingCallData = [
                'type' => 'incoming_call',
                'session_id' => $sessionId,
                'from' => $userId,
                'to' => $toUserId,
                'chat_id' => $chatId,
                'caller_name' => $callerName,
                'sdp' => $sdpOffer, // ⭐⭐ ¡ESTO ES LO QUE FALTA! ⭐⭐
                'timestamp' => $data['timestamp'] ?? date('Y-m-d H:i:s')
            ];

            echo "📤 Enviando incoming_call CON SDP incluido\n";
            $toConnection->send(json_encode($incomingCallData));
            echo "✅ Incoming call enviado al destinatario (con SDP)\n";

            // ⭐⭐ OPCIONAL: También enviar como call_offer por compatibilidad ⭐⭐
            $sdpData = [
                'type' => 'call_offer',
                'session_id' => $sessionId,
                'from' => $userId,
                'to' => $toUserId,
                'sdp' => $sdpOffer,
                'timestamp' => date('Y-m-d H:i:s')
            ];

            $toConnection->send(json_encode($sdpData));
            echo "📤 Oferta SDP también enviada por separado\n";

            // Confirmar al llamante que la llamada fue iniciada
            $from->send(json_encode([
                'type' => 'call_initiated',
                'session_id' => $sessionId,
                'to' => $toUserId,
                'chat_id' => $chatId,
                'status' => 'ringing',
                'timestamp' => date('Y-m-d H:i:s'),
                'message' => 'Llamando...',
                'caller_name' => $callerName
            ]));
            echo "✅ Confirmación enviada al llamante\n";
        } else {
            // Destinatario no conectado
            echo "❌ Destinatario {$toUserId} no conectado\n";
            $from->send(json_encode([
                'type' => 'user_offline',
                'session_id' => $sessionId,
                'message' => 'El usuario no está disponible',
                'status' => 'offline',
                'to' => $toUserId
            ]));
        }

        echo "📞 ========== LLAMADA PROCESADA ==========\n\n";
    }


    private function getUserIdFromConnection($connection)
    {
        echo "🔍 getUserIdFromConnection - Buscando userId para conexión #{$connection->resourceId}\n";

        // DEBUG: Mostrar todas las propiedades de la conexión
        echo "📋 Propiedades de la conexión #{$connection->resourceId}:\n";
        $props = [];
        foreach ($connection as $key => $value) {
            if (!is_object($value)) {
                $props[$key] = $value;
            }
        }
        echo "  " . json_encode($props) . "\n";

        // OPCIÓN 1: Verificar si ya tiene userId asignado
        if (isset($connection->userId)) {
            echo "✅ userId encontrado en propiedad directa: {$connection->userId}\n";
            return (int)$connection->userId;
        }

        // OPCIÓN 2: Buscar en $this->userConnections
        echo "🔍 Buscando en userConnections...\n";
        foreach ($this->userConnections as $userId => $connections) {
            foreach ($connections as $connId => $conn) {
                if ($connId === $connection->resourceId) {
                    echo "✅ Encontrado en userConnections: usuario {$userId}, conexión #{$connId}\n";
                    // Actualizar propiedad para futuras consultas
                    $connection->userId = (int)$userId;
                    return (int)$userId;
                }
            }
        }

        // OPCIÓN 3: Buscar por referencia de objeto
        echo "🔍 Buscando por referencia de objeto...\n";
        foreach ($this->userConnections as $userId => $connections) {
            foreach ($connections as $connId => $conn) {
                if ($conn === $connection) {
                    echo "✅ Encontrado por referencia: usuario {$userId}, conexión #{$connId}\n";
                    $connection->userId = (int)$userId;
                    return (int)$userId;
                }
            }
        }

        echo "❌ ERROR: No se pudo encontrar userId para conexión #{$connection->resourceId}\n";
        echo "⚠️ Esta conexión no está autenticada o hay un bug\n";

        // DEBUG: Mostrar estado actual
        $this->debugAllConnections();

        return null;
    }

    // Agrega este método para debug
    private function debugAllConnections()
    {
        echo "=== DEBUG DE TODAS LAS CONEXIONES ===\n";
        echo "Total clientes: " . count($this->clients) . "\n";
        echo "UserConnections:\n";
        foreach ($this->userConnections as $userId => $connections) {
            echo "  Usuario {$userId}:\n";
            foreach ($connections as $connId => $conn) {
                echo "    - Conexión #{$connId}";
                if (isset($conn->userId)) {
                    echo " (userId en propiedad: {$conn->userId})";
                }
                echo "\n";
            }
        }
        echo "===============================\n";
    }


    /**
     * Transmite mensaje a todos en un chat
     */

    private function handleAuth($from, $data)
    {
        echo "🔐 ========== AUTENTICACIÓN ==========\n";

        if (!isset($data['user_id'])) {
            echo "❌ ERROR: Falta user_id\n";
            return;
        }

        $userId = (int)$data['user_id'];
        $connId = $from->resourceId;

        echo "✅ Autenticando usuario {$userId} en conexión #{$connId}\n";

        // 1. Limpiar conexiones anteriores para este userId
        if (isset($this->userConnections[$userId])) {
            foreach ($this->userConnections[$userId] as $oldConnId => $oldConn) {
                if ($oldConnId != $connId) {
                    echo "  🧹 Removiendo conexión anterior #{$oldConnId}\n";

                    // Notificar cierre de sesión anterior
                    $oldConn->close();

                    // Limpiar estructuras
                    unset($this->userIdByConnectionId[$oldConnId]);
                    unset($this->connectionById[$oldConnId]);
                }
            }
        }

        // 2. Actualizar todas las estructuras de datos
        $this->userIdByConnectionId[$connId] = $userId;
        $this->connectionById[$connId] = $from;

        if (!isset($this->userConnections[$userId])) {
            $this->userConnections[$userId] = [];
        }
        $this->userConnections[$userId][$connId] = $from;

        // 3. Actualizar propiedades de la conexión
        $from->userId = $userId;
        $from->userData = $data['user_data'] ?? [];
        $from->authenticated = true;
        $from->authenticatedAt = time();

        // 4. Marcar como online
        $this->statusManager->setOnline($userId, $connId, $from->userData);

        // 5. Enviar confirmación
        $from->send(json_encode([
            'type' => 'auth_success',
            'user_id' => $userId,
            'connection_id' => $connId,
            'timestamp' => time(),
            'message' => 'Autenticación exitosa'
        ]));

        echo "✅ Usuario {$userId} autenticado exitosamente\n";
        echo "📊 Conexiones activas para usuario {$userId}: " . count($this->userConnections[$userId]) . "\n";
        echo "🔐 ========== AUTENTICACIÓN COMPLETADA ==========\n\n";
    }

    private function handleHeartbeat($from, $data)
    {
        if (!isset($from->userId)) return;

        $userId = $from->userId;
        $this->statusManager->updateActivity($userId);

        $from->send(json_encode([
            'type' => 'heartbeat_response',
            'timestamp' => time(),
            'user_id' => $userId,
            'online' => true
        ]));
    }

    private function handleGetOnlineUsers($from, $data)
    {
        $onlineUsers = $this->statusManager->getOnlineUsers($data['limit'] ?? 100);
        $stats = $this->statusManager->getStats();

        $from->send(json_encode([
            'type' => 'online_users_list',
            'users' => $onlineUsers,
            'stats' => $stats,
            'count' => count($onlineUsers),
            'timestamp' => time()
        ]));
    }

    private function handleGetUserStatus($from, $data)
    {
        if (!isset($data['user_id'])) {
            $from->send(json_encode(['type' => 'error', 'message' => 'Falta user_id']));
            return;
        }

        $userIds = is_array($data['user_id']) ? $data['user_id'] : [$data['user_id']];
        $statuses = $this->statusManager->getUsersStatus($userIds);

        $from->send(json_encode([
            'type' => 'users_status',
            'statuses' => $statuses,
            'timestamp' => time()
        ]));
    }

    private function handleJoinChat($from, $data)
    {
        if (!isset($data['chat_id'], $data['user_id'])) {
            $from->send(json_encode(['type' => 'error', 'message' => 'Datos incompletos']));
            return;
        }

        $chatId = (int)$data['chat_id'];
        $userId = (int)$data['user_id'];

        echo "➡ handleJoinChat: user {$userId} entra al chat {$chatId}\n";

        // 🔥🔥🔥 FIX IMPORTANTE 🔥🔥🔥
        // Mantener SIEMPRE al usuario dentro de userConnections
        if (!isset($this->userConnections[$userId])) {
            $this->userConnections[$userId] = [];
        }

        // Registrar o actualizar la conexión actual
        $this->userConnections[$userId][$from->resourceId] = $from;

        // Asegurar que la conexión tiene userId seteado
        $from->userId = $userId;
        // ----------------------------------------------------------

        // Registrar al usuario dentro del chat
        if (!isset($this->sessions[$chatId])) {
            $this->sessions[$chatId] = [];
            echo "💬 Nueva sesión creada para chat {$chatId}\n";
        }

        $this->sessions[$chatId][$from->resourceId] = $from;
        $from->currentChat = $chatId;

        echo "➕ Usuario {$userId} unido al chat {$chatId}\n";

        // Obtener lista de usuarios conectados en este chat
        $onlineInChat = $this->getOnlineUsersInChat($chatId);

        // Enviar confirmación al cliente
        $from->send(json_encode([
            'type' => 'joined_chat',
            'chat_id' => $chatId,
            'user_id' => $userId,
            'online_count' => count($this->sessions[$chatId]),
            'online_users' => $onlineInChat,
            'timestamp' => time()
        ]));

        // Notificar a los demás usuarios del chat
        $this->notifyUserJoinedChat($chatId, $userId);
    }


    private function handlePing($from)
    {
        $from->send(json_encode([
            'type' => 'pong',
            'timestamp' => time(),
            'server_time' => date('H:i:s'),
            'online' => isset($from->userId)
        ]));
        echo "🏓 Ping respondido\n";
    }

    // ===================== MÉTODOS AUXILIARES =====================

    private function startHeartbeatTimer($conn)
    {
        if (!isset($conn->userId)) return;

        if (isset($this->userTimers[$conn->resourceId])) {
            $timer = $this->userTimers[$conn->resourceId];
            if ($timer && $timer instanceof \React\EventLoop\TimerInterface) {
                \React\EventLoop\Loop::cancelTimer($timer);
            }
        }

        $timer = \React\EventLoop\Loop::addPeriodicTimer(30, function () use ($conn) {
            if ($conn->userId) {
                $this->statusManager->updateActivity($conn->userId);

                $conn->send(json_encode([
                    'type' => 'server_heartbeat',
                    'timestamp' => time(),
                    'online' => true
                ]));
            }
        });

        $this->userTimers[$conn->resourceId] = $timer;
    }

    private function getOnlineUsersInChat($chatId)
    {
        $onlineUsers = [];

        if (isset($this->sessions[$chatId])) {
            foreach ($this->sessions[$chatId] as $conn) {
                if (isset($conn->userId)) {
                    $status = $this->statusManager->getUserStatus($conn->userId);
                    $onlineUsers[$conn->userId] = array_merge($status, [
                        'connection_id' => $conn->resourceId,
                        'in_chat' => true
                    ]);
                }
            }
        }

        return array_values($onlineUsers);
    }

    private function notifyUserStatusChange($userId, $status, $data = [])
    {
        $message = [
            'type' => 'user_status_change',
            'user_id' => $userId,
            'status' => $status,
            'timestamp' => time(),
            'data' => $data
        ];

        // Enviar a conexiones del usuario
        if (isset($this->userConnections[$userId])) {
            foreach ($this->userConnections[$userId] as $conn) {
                try {
                    $conn->send(json_encode($message));
                } catch (\Exception $e) {
                    echo "⚠️ Error enviando status a usuario {$userId}: {$e->getMessage()}\n";
                }
            }
        }

        // Enviar a chats donde está el usuario
        foreach ($this->sessions as $chatId => $connections) {
            $userInChat = false;
            foreach ($connections as $conn) {
                if (isset($conn->userId) && $conn->userId == $userId) {
                    $userInChat = true;
                    break;
                }
            }

            if ($userInChat) {
                foreach ($connections as $conn) {
                    if (isset($conn->userId) && $conn->userId != $userId) {
                        try {
                            $conn->send(json_encode($message));
                        } catch (\Exception $e) {
                            echo "⚠️ Error enviando status en chat {$chatId}: {$e->getMessage()}\n";
                        }
                    }
                }
            }
        }

        echo "📢 Notificado cambio de estado: {$userId} -> {$status}\n";
    }

    private function notifyUserJoinedChat($chatId, $userId)
    {
        if (!isset($this->sessions[$chatId])) return;

        $userStatus = $this->statusManager->getUserStatus($userId);

        $message = [
            'type' => 'user_joined_chat',
            'chat_id' => $chatId,
            'user_id' => $userId,
            'status' => $userStatus,
            'timestamp' => time()
        ];

        foreach ($this->sessions[$chatId] as $conn) {
            if (isset($conn->userId) && $conn->userId != $userId) {
                try {
                    $conn->send(json_encode($message));
                } catch (\Exception $e) {
                    echo "⚠️ Error notificando unión al chat: {$e->getMessage()}\n";
                }
            }
        }
    }

    private function notifyUserLeftChat($chatId, $userId)
    {
        if (!isset($this->sessions[$chatId])) return;

        $message = [
            'type' => 'user_left_chat',
            'chat_id' => $chatId,
            'user_id' => $userId,
            'timestamp' => time()
        ];

        foreach ($this->sessions[$chatId] as $conn) {
            if (isset($conn->userId) && $conn->userId != $userId) {
                try {
                    $conn->send(json_encode($message));
                } catch (\Exception $e) {
                    echo "⚠️ Error notificando salida del chat: {$e->getMessage()}\n";
                }
            }
        }
    }

    // ===================== MANEJO DE MENSAJES Y ARCHIVOS =====================

    private function handleFileUpload($from, $data)
    {
        $this->logToFile("📁 Procesando notificación de archivo subido");

        $chatId = $data['chat_id'] ?? null;
        $userId = $data['user_id'] ?? null;

        if (!$chatId || !$userId) {
            $this->logToFile("❌ Datos incompletos");
            return;
        }

        $this->logToFile("✅ Notificación válida - Chat: $chatId, User: $userId");

        // ⭐⭐ GUARDAR ARCHIVO EN BD SI TENEMOS DATOS COMPLETOS
        $fileId = null;
        $realChatId = $chatId;

        if ($this->chatModel && isset($data['file_info'])) {
            try {
                // Verificar si el chat existe
                if (!$this->chatModel->chatExists($chatId)) {
                    $otherUserId = $data['other_user_id'] ?? $chatId;
                    $realChatId = $this->chatModel->findChatBetweenUsers($userId, $otherUserId);

                    if (!$realChatId) {
                        $realChatId = $this->chatModel->createChat([$userId, $otherUserId]);
                        $this->logToFile("🆕 Chat creado para archivo: {$realChatId}");
                    }

                    $chatId = $realChatId;
                }

                // Preparar datos del archivo
                $fileData = [
                    'name' => $data['file_info']['name'] ?? basename($data['file_url'] ?? 'archivo'),
                    'original_name' => $data['file_original_name'] ?? $data['contenido'] ?? 'archivo',
                    'path' => $data['file_info']['path'] ?? '',
                    'url' => $data['file_url'] ?? $data['url'] ?? '',
                    'size' => $data['file_size'] ?? $data['file_info']['size'] ?? 0,
                    'mime_type' => $data['file_mime_type'] ?? $data['file_info']['mime_type'] ?? 'application/octet-stream',
                    'chat_id' => $chatId,
                    'user_id' => $userId
                ];

                // Guardar archivo en BD
                $fileId = $this->chatModel->saveFile($fileData);
                $this->logToFile("💾 Archivo guardado en BD con ID: {$fileId}");

                // Guardar mensaje referenciando el archivo
                $contenido = $data['contenido'] ?? $data['file_original_name'] ?? 'Archivo';
                $tipo = strpos($fileData['mime_type'], 'image/') === 0 ? 'imagen' : 'archivo';

                $messageId = $this->chatModel->sendMessage(
                    $chatId,
                    $userId,
                    $contenido,
                    $tipo,
                    $fileId
                );

                $this->logToFile("✅ Mensaje de archivo guardado: ID {$messageId}");

                // Actualizar conteos no leídos
                $this->updateUnreadCounts($chatId, $userId);
            } catch (\Exception $e) {
                $this->logToFile("❌ Error guardando archivo en BD: " . $e->getMessage());
                $fileId = null;
            }
        }

        // ⭐⭐ PREPARAR MENSAJE PARA BROADCAST
        $broadcastMessage = [
            'type' => $data['type'], // 'image_upload' o 'file_upload'
            'message_id' => $data['message_id'] ?? $messageId ?? uniqid(),
            'chat_id' => $chatId,
            'user_id' => $userId,
            'contenido' => $data['contenido'] ?? $data['file_original_name'] ?? 'Archivo',
            'tipo' => $data['tipo'] ?? ($data['type'] == 'image_upload' ? 'imagen' : 'archivo'),
            'timestamp' => $data['timestamp'] ?? date('c'),
            'leido' => 0,
            'status' => 'delivered',
            'file_id' => $fileId,
            'action' => 'file_uploaded'
        ];

        // Agregar TODOS los datos del archivo
        if (isset($data['file_url'])) {
            $broadcastMessage['file_url'] = $data['file_url'];
        }

        if (isset($data['url'])) {
            $broadcastMessage['url'] = $data['url'];
        }

        if (isset($data['file_info'])) {
            $broadcastMessage['file_info'] = $data['file_info'];
        }

        if (isset($data['file_original_name'])) {
            $broadcastMessage['file_original_name'] = $data['file_original_name'];
        }

        if (isset($data['file_size'])) {
            $broadcastMessage['file_size'] = $data['file_size'];
        }

        if (isset($data['file_type'])) {
            $broadcastMessage['file_type'] = $data['file_type'];
        }

        if (isset($data['mime_type'])) {
            $broadcastMessage['mime_type'] = $data['mime_type'];
        }

        if (isset($data['file_mime_type'])) {
            $broadcastMessage['file_mime_type'] = $data['file_mime_type'];
        }

        // ⭐⭐ ENVIAR A TODOS EN EL CHAT (INCLUYENDO AL REMITENTE)
        $sentCount = 0;
        if (isset($this->sessions[$chatId])) {
            foreach ($this->sessions[$chatId] as $client) {
                try {
                    $client->send(json_encode($broadcastMessage));
                    $sentCount++;

                    // ⭐⭐ NOTIFICAR ACTUALIZACIÓN DE CHAT A OTROS USUARIOS
                    if (isset($client->userId) && $client->userId != $userId) {
                        $this->notifyNewFile($chatId, $broadcastMessage, $userId);
                    }
                } catch (\Exception $e) {
                    $this->logToFile("❌ Error enviando: {$e->getMessage()}");
                }
            }
        } else {
            $this->logToFile("⚠️ No hay sesiones activas para chat $chatId");
            // Enviar solo al remitente
            $from->send(json_encode($broadcastMessage));
            $sentCount = 1;
        }

        // ⭐⭐ NOTIFICAR ACTUALIZACIÓN EN LISTA DE CHATS
        $this->notifyChatListUpdate($chatId, [
            'contenido' => $broadcastMessage['contenido'],
            'user_id' => $userId,
            'tipo' => $broadcastMessage['tipo'],
            'timestamp' => $broadcastMessage['timestamp']
        ]);

        $this->logToFile("📤 Mensaje de archivo enviado a {$sentCount} cliente(s) en chat {$chatId}");
    }

    // ⭐⭐ NUEVO MÉTODO PARA NOTIFICAR ARCHIVOS
    private function notifyNewFile($chatId, $fileData, $senderId)
    {
        $message = [
            'type' => 'new_file',
            'chat_id' => $chatId,
            'file_data' => $fileData,
            'sender_id' => $senderId,
            'timestamp' => time(),
            'action' => 'file_received'
        ];

        // Enviar notificación especial para archivos
        $this->broadcastToChat($chatId, $message);

        // También actualizar lista de chats
        $this->notifyChatListUpdate($chatId, [
            'contenido' => $fileData['tipo'] == 'imagen' ? '📷 Imagen' : '📎 Archivo',
            'user_id' => $senderId,
            'tipo' => $fileData['tipo'],
            'timestamp' => $fileData['timestamp'],
            'is_file' => true
        ]);
    }
    // Agrega este método a la clase SignalServer
    private function handleFileUploadNotification($from, $data)
    {
        // Este es un handler específico para notificaciones de subida de archivos
        $this->logToFile("📁 Procesando notificación de archivo completo");

        $chatId = $data['chat_id'] ?? null;
        $userId = $data['user_id'] ?? null;

        if (!$chatId || !$userId) {
            return;
        }

        // Preparar datos para guardar
        $fileData = [
            'name' => $data['file_name'] ?? 'archivo',
            'original_name' => $data['file_original_name'] ?? 'archivo',
            'path' => $data['file_path'] ?? '',
            'url' => $data['file_url'] ?? $data['url'] ?? '',
            'size' => $data['file_size'] ?? 0,
            'mime_type' => $data['file_mime_type'] ?? 'application/octet-stream',
            'chat_id' => $chatId,
            'user_id' => $userId
        ];

        // Guardar en BD
        $fileId = null;
        if ($this->chatModel) {
            try {
                $fileId = $this->chatModel->saveFile($fileData);
                $this->logToFile("💾 Archivo guardado con ID: {$fileId}");

                // Crear mensaje asociado
                $contenido = $data['contenido'] ?? $data['file_original_name'] ?? 'Archivo';
                $tipo = strpos($fileData['mime_type'], 'image/') === 0 ? 'imagen' : 'archivo';

                $messageId = $this->chatModel->sendMessage(
                    $chatId,
                    $userId,
                    $contenido,
                    $tipo,
                    $fileId
                );

                // Preparar respuesta
                $response = [
                    'type' => 'file_upload_complete',
                    'message_id' => $messageId,
                    'file_id' => $fileId,
                    'chat_id' => $chatId,
                    'user_id' => $userId,
                    'file_url' => $fileData['url'],
                    'file_name' => $fileData['original_name'],
                    'file_size' => $fileData['size'],
                    'mime_type' => $fileData['mime_type'],
                    'timestamp' => date('c'),
                    'status' => 'uploaded'
                ];

                // Enviar confirmación
                $from->send(json_encode($response));

                // Notificar a otros en el chat
                $this->notifyNewFile($chatId, array_merge($response, [
                    'contenido' => $contenido,
                    'tipo' => $tipo
                ]), $userId);
            } catch (\Exception $e) {
                $this->logToFile("❌ Error procesando archivo: " . $e->getMessage());

                $from->send(json_encode([
                    'type' => 'file_upload_error',
                    'error' => $e->getMessage()
                ]));
            }
        }
    }
    private function handleChatMessage($from, $data)
    {
        $this->logToFile("💭 Procesando mensaje de chat");

        $chatId = $data['chat_id'] ?? null;
        $userId = $data['user_id'] ?? null;
        $content = $data['contenido'] ?? '';
        $tempId = $data['temp_id'] ?? null;

        if (!$chatId || !$userId) {
            $this->logToFile("❌ Datos incompletos");
            return;
        }

        // 1. Confirmación inmediata
        if ($tempId) {
            $from->send(json_encode([
                'type' => 'message_ack',
                'temp_id' => $tempId,
                'status' => 'received',
                'timestamp' => time()
            ]));
        }

        // 2. Guardar en BD
        $messageId = null;
        $realChatId = $chatId;
        $otherUserId = null;

        if ($this->chatModel) {
            try {
                // Verificar y/o crear chat
                if (!$this->chatModel->chatExists($chatId)) {
                    $otherUserId = $data['other_user_id'] ?? $chatId;
                    $realChatId = $this->chatModel->findChatBetweenUsers($userId, $otherUserId);

                    if (!$realChatId) {
                        $realChatId = $this->chatModel->createChat([$userId, $otherUserId]);
                        $this->logToFile("🆕 Chat creado: {$realChatId}");
                    }

                    $chatId = $realChatId;
                }

                // Guardar mensaje y obtener ID REAL
                $messageId = $this->chatModel->sendMessage(
                    $chatId,
                    $userId,
                    $content,
                    $data['tipo'] ?? 'texto'
                );

                $this->logToFile("✅ Mensaje guardado en BD: ID REAL {$messageId}");

                // ⭐⭐ ENVIAR CONFIRMACIÓN CON ID REAL AL REMITENTE
                if ($tempId && $messageId) {
                    $confirmation = [
                        'type' => 'message_sent', // ⭐⭐ TIPO NUEVO
                        'message_id' => $messageId, // ⭐⭐ ID REAL
                        'temp_id' => $tempId, // ⭐⭐ ID TEMPORAL
                        'chat_id' => $chatId,
                        'user_id' => $userId,
                        'contenido' => $content,
                        'tipo' => $data['tipo'] ?? 'texto',
                        'timestamp' => date('c'),
                        'status' => 'sent',
                        'action' => 'message_confirmed' // ⭐⭐ ACCIÓN
                    ];

                    // Enviar confirmación SOLO al remitente
                    $from->send(json_encode($confirmation));
                    $this->logToFile("✅ Confirmación enviada al remitente: temp_id={$tempId}, message_id={$messageId}");
                }

                // Obtener conteo de mensajes no leídos para cada usuario
                $this->updateUnreadCounts($chatId, $userId);
            } catch (\Exception $e) {
                $this->logToFile("❌ Error BD: " . $e->getMessage());
                // Si hay error, enviar confirmación de error
                if ($tempId) {
                    $from->send(json_encode([
                        'type' => 'message_error',
                        'temp_id' => $tempId,
                        'error' => $e->getMessage(),
                        'status' => 'failed'
                    ]));
                }
                return; // Salir si hay error
            }
        } else {
            $messageId = 'temp_' . rand(1000, 9999);
        }

        // 3. Preparar respuesta del mensaje para broadcast
        $response = [
            'type' => 'chat_message',
            'message_id' => $messageId, // ⭐⭐ Esto ya es el ID REAL si se guardó
            'chat_id' => $chatId,
            'user_id' => $userId,
            'contenido' => $content,
            'tipo' => $data['tipo'] ?? 'texto',
            'timestamp' => date('c'),
            'temp_id' => $tempId,
            'leido' => 0,
            'user_name' => $data['user_name'] ?? 'Usuario',
            'status' => 'sent',
            'action' => 'new_message'
        ];

        // 4. Enviar a todos en el chat (EXCEPTO AL REMITENTE - ya recibió confirmación)
        $sentCount = 0;

        if (isset($this->sessions[$chatId])) {
            foreach ($this->sessions[$chatId] as $client) {
                // ⭐⭐ NO enviar al remitente (ya recibió confirmación)
                if ($client === $from) continue;

                try {
                    $client->send(json_encode($response));
                    $sentCount++;
                } catch (\Exception $e) {
                    $this->logToFile("❌ Error enviando a cliente: {$e->getMessage()}");
                }
            }
        }

        $this->logToFile("📤 Mensaje broadcast a {$sentCount} otros cliente(s)");
    }

    /**
     * Obtener datos actualizados del chat para la lista
     */
    private function getChatUpdateData($chatId, $excludeUserId)
    {
        try {
            if (!$this->chatModel) return null;

            // Obtener información del chat
            $sql = "SELECT 
                    c.id as chat_id,
                    c.name as chat_name,
                    c.last_message_at,
                    u.id as other_user_id,
                    u.name as other_user_name,
                    u.avatar as other_user_avatar,
                    (SELECT contenido FROM mensajes WHERE chat_id = c.id ORDER BY id DESC LIMIT 1) as last_message,
                    (SELECT COUNT(*) FROM mensajes WHERE chat_id = c.id AND leido = 0 AND user_id != ?) as unread_count
                FROM chats c
                JOIN chat_usuarios cu1 ON c.id = cu1.chat_id AND cu1.user_id = ?
                JOIN chat_usuarios cu2 ON c.id = cu2.chat_id AND cu2.user_id != ?
                JOIN users u ON u.id = cu2.user_id
                WHERE c.id = ?
                LIMIT 1";

            $result = $this->chatModel->query($sql, [$excludeUserId, $excludeUserId, $excludeUserId, $chatId]);

            if (!empty($result)) {
                $chatData = $result[0];

                return [
                    'chat_id' => $chatData['chat_id'],
                    'chat_name' => $chatData['chat_name'] ?? 'Chat privado',
                    'last_message' => $chatData['last_message'] ?? '',
                    'last_message_at' => $chatData['last_message_at'],
                    'unread_count' => (int)$chatData['unread_count'],
                    'other_user' => [
                        'id' => $chatData['other_user_id'],
                        'name' => $chatData['other_user_name'],
                        'avatar' => $chatData['other_user_avatar']
                    ],
                    'updated_at' => date('c')
                ];
            }

            return null;
        } catch (\Exception $e) {
            $this->logToFile("❌ Error en getChatUpdateData: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Preparar actualización de chat para un usuario específico
     */
    private function prepareChatUpdateForUser($chatId, $userId, $messageData)
    {
        try {
            if (!$this->chatModel) {
                return [
                    'type' => 'chat_updated',
                    'chat_id' => $chatId,
                    'action' => 'bump',
                    'timestamp' => time()
                ];
            }

            // Obtener datos específicos para este usuario
            $sql = "SELECT 
                    c.id as chat_id,
                    c.name as chat_name,
                    c.last_message_at,
                    (SELECT contenido FROM mensajes WHERE chat_id = c.id ORDER BY id DESC LIMIT 1) as last_message,
                    (SELECT COUNT(*) FROM mensajes WHERE chat_id = c.id AND leido = 0 AND user_id != ?) as unread_count
                FROM chats c
                WHERE c.id = ?";

            $result = $this->chatModel->query($sql, [$userId, $chatId]);

            if (!empty($result)) {
                $chatData = $result[0];

                return [
                    'type' => 'chat_updated',
                    'action' => 'new_message',
                    'chat_id' => $chatId,
                    'data' => [
                        'chat_id' => $chatData['chat_id'],
                        'chat_name' => $chatData['chat_name'] ?? 'Chat privado',
                        'last_message' => $messageData['contenido'] ?? $chatData['last_message'],
                        'last_message_at' => $chatData['last_message_at'],
                        'unread_count' => (int)$chatData['unread_count'] + 1, // Incrementar contador
                        'sender_id' => $messageData['user_id'] ?? null,
                        'sender_name' => $messageData['user_name'] ?? 'Usuario',
                        'message_type' => $messageData['tipo'] ?? 'texto',
                        'preview' => $this->getMessagePreview($messageData['contenido'] ?? '', $messageData['tipo'] ?? 'texto'),
                        'timestamp' => date('c')
                    ]
                ];
            }

            return [
                'type' => 'chat_updated',
                'chat_id' => $chatId,
                'action' => 'update',
                'timestamp' => time()
            ];
        } catch (\Exception $e) {
            $this->logToFile("❌ Error en prepareChatUpdateForUser: " . $e->getMessage());
            return [
                'type' => 'chat_updated',
                'chat_id' => $chatId,
                'action' => 'refresh',
                'timestamp' => time()
            ];
        }
    }

    /**
     * Enviar notificación de nuevo mensaje
     */
    private function sendNewMessageNotification($client, $chatId, $messageData)
    {
        try {
            $notification = [
                'type' => 'new_message_notification',
                'chat_id' => $chatId,
                'message_id' => $messageData['message_id'] ?? null,
                'sender_id' => $messageData['user_id'] ?? null,
                'sender_name' => $messageData['user_name'] ?? 'Alguien',
                'preview' => $this->getMessagePreview($messageData['contenido'] ?? '', $messageData['tipo'] ?? 'texto'),
                'message_type' => $messageData['tipo'] ?? 'texto',
                'unread_count' => 1,
                'timestamp' => time(),
                'sound' => true, // Para que el frontend reproduzca sonido
                'badge' => true  // Para que el frontend actualice el badge
            ];

            $client->send(json_encode($notification));
            $this->logToFile("🔔 Notificación enviada a usuario {$client->userId}");
        } catch (\Exception $e) {
            $this->logToFile("❌ Error enviando notificación: " . $e->getMessage());
        }
    }

    /**
     * Obtener preview del mensaje
     */
    private function getMessagePreview($content, $type)
    {
        if ($type === 'imagen') {
            return '📷 Imagen';
        } elseif ($type === 'archivo') {
            return '📎 Archivo';
        } elseif ($type === 'audio') {
            return '🎵 Audio';
        } else {
            // Limitar texto a 50 caracteres
            return strlen($content) > 50 ? substr($content, 0, 47) . '...' : $content;
        }
    }



    /**
     * Broadcast actualización de conteo no leído
     */
    private function broadcastUnreadCountUpdate($userId)
    {
        try {
            $totalUnread = $this->getTotalUnreadCount($userId);

            // Buscar todas las conexiones de este usuario
            foreach ($this->clients as $client) {
                if (isset($client->userId) && $client->userId == $userId) {
                    $client->send(json_encode([
                        'type' => 'unread_count_update',
                        'total_unread' => $totalUnread,
                        'timestamp' => time()
                    ]));
                }
            }
        } catch (\Exception $e) {
            $this->logToFile("❌ Error en broadcastUnreadCountUpdate: " . $e->getMessage());
        }
    }

    /**
     * Obtener conteo total de no leídos para un usuario
     */
    private function getTotalUnreadCount($userId)
    {
        try {
            if (!$this->chatModel) return 0;

            $sql = "SELECT SUM(unread_count) as total 
                FROM (
                    SELECT COUNT(*) as unread_count 
                    FROM mensajes m
                    JOIN chats c ON m.chat_id = c.id
                    JOIN chat_usuarios cu ON c.id = cu.chat_id
                    WHERE cu.user_id = ? 
                    AND m.user_id != ? 
                    AND m.leido = 0
                    GROUP BY m.chat_id
                ) as counts";

            $result = $this->chatModel->query($sql, [$userId, $userId]);

            return !empty($result) ? (int)$result[0]['total'] : 0;
        } catch (\Exception $e) {
            $this->logToFile("❌ Error en getTotalUnreadCount: " . $e->getMessage());
            return 0;
        }
    }
    private function handleMarkAsRead($from, $data)
    {
        $chatId = $data['chat_id'] ?? null;
        $userId = $data['user_id'] ?? null;

        if (!$chatId || !$userId) {
            $from->send(json_encode(['type' => 'error', 'message' => 'Datos incompletos']));
            return;
        }

        $this->logToFile("📖 Marcando mensajes como leídos - Chat: {$chatId}, User: {$userId}");

        if ($this->chatModel) {
            try {
                // Marcar como leído en BD
                $markedCount = $this->chatModel->markMessagesAsRead($chatId, $userId);

                // Notificar que los mensajes fueron leídos
                $this->notifyMessagesRead($chatId, $userId, $markedCount);

                // Resetear conteo no leído
                $this->notifyUnreadCount($chatId, $userId, 0);

                $this->logToFile("✅ {$markedCount} mensajes marcados como leídos");

                $from->send(json_encode([
                    'type' => 'messages_read_ack',
                    'chat_id' => $chatId,
                    'user_id' => $userId,
                    'count' => $markedCount,
                    'timestamp' => time()
                ]));
            } catch (\Exception $e) {
                $this->logToFile("❌ Error marcando como leído: " . $e->getMessage());
            }
        }
    }

    private function notifyMessagesRead($chatId, $userId, $count)
    {
        $message = [
            'type' => 'messages_read',
            'chat_id' => $chatId,
            'user_id' => $userId,
            'count' => $count,
            'timestamp' => time()
        ];

        // Notificar al remitente original que sus mensajes fueron leídos
        if (isset($this->sessions[$chatId])) {
            foreach ($this->sessions[$chatId] as $client) {
                if (isset($client->userId) && $client->userId != $userId) {
                    try {
                        $client->send(json_encode($message));
                    } catch (\Exception $e) {
                        $this->logToFile("❌ Error notificando mensajes leídos: {$e->getMessage()}");
                    }
                }
            }
        }
    }
    private function updateUnreadCounts($chatId, $senderId)
    {
        if (!$this->chatModel) return;

        try {
            // Obtener todos los usuarios en el chat excepto el remitente
            $sql = "SELECT user_id FROM chat_usuarios WHERE chat_id = ? AND user_id != ?";
            $results = $this->chatModel->query($sql, [$chatId, $senderId]);

            foreach ($results as $row) {
                $userId = $row['user_id'];

                // Obtener conteo actual de mensajes no leídos
                $countSql = "SELECT COUNT(*) as unread_count 
                        FROM mensajes 
                        WHERE chat_id = ? 
                        AND user_id != ? 
                        AND leido = 0";
                $countResult = $this->chatModel->query($countSql, [$chatId, $userId]);

                $unreadCount = $countResult[0]['unread_count'] ?? 0;

                // Notificar al usuario
                $this->notifyUnreadCount($chatId, $userId, $unreadCount);
            }
        } catch (\Exception $e) {
            $this->logToFile("❌ Error actualizando conteos no leídos: " . $e->getMessage());
        }
    }

    private function broadcastToChat($chatId, $message, $excludeConnection = null)
    {
        if (!isset($this->sessions[$chatId])) return 0;

        $sentCount = 0;
        foreach ($this->sessions[$chatId] as $conn) {
            // ⭐⭐ CORRECCIÓN: Comparar objetos de conexión en lugar de IDs
            if ($excludeConnection && $conn === $excludeConnection) continue;

            try {
                $conn->send(json_encode($message));
                $sentCount++;
            } catch (\Exception $e) {
                echo "❌ Error enviando mensaje: {$e->getMessage()}\n";
            }
        }

        return $sentCount;
    }

    private function handleTest($from, $data)
    {
        $stats = $this->statusManager->getStats();

        $response = [
            'type' => 'test_response',
            'message' => 'WebSocket funcionando',
            'server_time' => date('c'),
            'clients_count' => $this->clients->count(),
            'online_users' => $stats['online_users'] ?? 0,
            'chat_model_status' => $this->chatModel ? 'active' : 'inactive'
        ];

        $from->send(json_encode($response));
        echo "✅ Test respondido\n";
    }

    // ===================== VERIFICACIÓN DE NOTIFICACIONES PENDIENTES =====================

    public function checkDatabaseNotifications()
    {
        try {
            $this->logToFile("🔍 Verificando notificaciones pendientes");

            if (!$this->chatModel) {
                $this->logToFile("⚠️ ChatModel no disponible");
                return;
            }

            // Usar ChatModel para consultar notificaciones pendientes
            $sql = "SELECT id, chat_id, user_id, message_type, message_data, created_at 
                    FROM websocket_notifications 
                    WHERE status = 'pending' 
                    AND processed_at IS NULL 
                    ORDER BY created_at ASC 
                    LIMIT 10";

            $notifications = $this->chatModel->query($sql);

            if (empty($notifications)) {
                $this->logToFile("✅ No hay notificaciones pendientes");
                return;
            }

            $this->logToFile("📦 Encontradas " . count($notifications) . " notificaciones");

            foreach ($notifications as $notification) {
                $this->processNotification($notification);
            }
        } catch (\Exception $e) {
            $this->logToFile("❌ Error en checkDatabaseNotifications: " . $e->getMessage());
        }
    }

    private function processNotification($notification)
    {
        try {
            $messageData = json_decode($notification['message_data'], true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logToFile("❌ JSON inválido en notificación ID: " . $notification['id']);
                $this->markAsProcessed($notification['id'], 'error');
                return;
            }

            $this->logToFile("🔄 Procesando notificación ID: {$notification['id']}, tipo: {$notification['message_type']}");

            // Preparar datos para broadcast
            $broadcastData = [
                'type' => $notification['message_type'],
                'chat_id' => $messageData['chat_id'],
                'user_id' => $messageData['user_id'],
                'contenido' => $messageData['contenido'] ?? $messageData['file_original_name'] ?? 'Archivo',
                'tipo' => $messageData['tipo'] ?? ($notification['message_type'] == 'image_upload' ? 'imagen' : 'archivo'),
                'timestamp' => $notification['created_at'],
                'message_id' => $notification['id'],
                'file_url' => $messageData['file_url'] ?? '',
                'file_original_name' => $messageData['file_original_name'] ?? '',
                'file_size' => $messageData['file_size'] ?? 0,
                'file_mime_type' => $messageData['file_mime_type'] ?? '',
                'status' => 'delivered'
            ];

            // Enviar a todos en el chat
            if (isset($this->sessions[$messageData['chat_id']])) {
                $sentCount = 0;
                foreach ($this->sessions[$messageData['chat_id']] as $client) {
                    try {
                        $client->send(json_encode($broadcastData));
                        $sentCount++;
                    } catch (\Exception $e) {
                        $this->logToFile("❌ Error enviando: {$e->getMessage()}");
                    }
                }
                $this->logToFile("✅ Notificación enviada a {$sentCount} clientes");
            } else {
                $this->logToFile("⚠️ No hay usuarios conectados en chat {$messageData['chat_id']}");
            }

            // Marcar como procesado
            $this->markAsProcessed($notification['id'], 'processed');
        } catch (\Exception $e) {
            $this->logToFile("❌ Error procesando notificación {$notification['id']}: " . $e->getMessage());
            $this->markAsProcessed($notification['id'], 'error');
        }
    }

    private function markAsProcessed($notificationId, $status = 'processed')
    {
        try {
            if (!$this->chatModel) {
                $this->logToFile("⚠️ ChatModel no disponible para marcar como procesado");
                return false;
            }

            $sql = "UPDATE websocket_notifications 
                    SET status = ?, 
                        processed_at = NOW() 
                    WHERE id = ?";

            $result = $this->chatModel->query($sql, [$status, $notificationId]);

            if ($result) {
                $this->logToFile("✅ Notificación {$notificationId} marcada como {$status}");
                return true;
            } else {
                $this->logToFile("❌ Error al marcar notificación {$notificationId}");
                return false;
            }
        } catch (\Exception $e) {
            $this->logToFile("❌ Error en markAsProcessed: " . $e->getMessage());
            return false;
        }
    }

    public function periodicCleanup()
    {
        $cleaned = $this->statusManager->cleanupStaleConnections();

        if ($cleaned > 0) {
            echo "🧹 Limpiadas {$cleaned} conexiones inactivas\n";
        }

        static $statsCounter = 0;
        $statsCounter++;

        if ($statsCounter >= 10) {
            $stats = $this->statusManager->getStats();
            echo "📊 Estadísticas: " . json_encode($stats) . "\n";
            $statsCounter = 0;
        }
    }
}

// ===================== INICIAR SERVIDOR =====================
echo "\n";
echo "========================================\n";
echo "🚀 INICIANDO SERVIDOR WEBSOCKET MEJORADO\n";
echo "========================================\n\n";
try {

    require_once __DIR__ . '/AudioCallServer.php';

    // Función de logging
    function logToFilegral($message)
    {
        $logFile = __DIR__ . '/websocket_debug_gral.log';
        $timestamp = date('Y-m-d H:i:s');
        $formattedMessage = "[$timestamp] " . $message . "\n";
        file_put_contents($logFile, $formattedMessage, FILE_APPEND | LOCK_EX);

        if (php_sapi_name() === 'cli') {
            echo $formattedMessage;
        }
    }

    logToFilegral("================================================");
    logToFilegral("🚀 INICIANDO SERVIDOR WEBSOCKET UNIFICADO");
    logToFilegral("================================================");

    $loop = \React\EventLoop\Factory::create();
    $webSock = new \React\Socket\Server('0.0.0.0:9090', $loop);

    $chatApp  = new SignalServer();      // Chat
    $audioApp = new AudioCallApp\AudioCallServer();   // Audio/TURN

    // Servidor WS unificado
    $wsServer = new \Ratchet\WebSocket\WsServer(
        new class($chatApp, $audioApp) implements \Ratchet\MessageComponentInterface {
            private $chatApp;
            private $audioApp;
            private $serverStartTime;

            public function __construct($chatApp, $audioApp)
            {
                $this->chatApp  = $chatApp;
                $this->audioApp = $audioApp;
                $this->serverStartTime = time();

                logToFilegral("🔄 Servidor unificado creado");
                logToFilegral("   - ChatApp: " . get_class($chatApp));
                logToFilegral("   - AudioApp: " . get_class($audioApp));
            }

            public function onOpen(\Ratchet\ConnectionInterface $conn)
            {
                logToFilegral("🔗 Conexión #{$conn->resourceId} abierta en servidor unificado");

                // Abrir en ambos
                try {
                    $this->chatApp->onOpen($conn);
                } catch (\Exception $e) {
                    logToFilegral("❌ Error en chatApp->onOpen: " . $e->getMessage());
                }

                try {
                    $this->audioApp->onOpen($conn);
                } catch (\Exception $e) {
                    logToFilegral("❌ Error en audioApp->onOpen: " . $e->getMessage());
                }
            }

            public function onMessage(\Ratchet\ConnectionInterface $from, $msg)
            {
                $connId = $from->resourceId;
                $msgPreview = is_string($msg) ? substr($msg, 0, 200) : "[BINARIO " . strlen($msg) . " bytes]";

                logToFilegral("📨 Conexión #{$connId} → {$msgPreview}");

                try {
                    if (is_string($msg) && json_decode($msg, true) !== null) {
                        $data = json_decode($msg, true);
                        $msgType = $data['type'] ?? 'unknown';

                        logToFilegral("🔍 Tipo de mensaje detectado: {$msgType}");
                        logToFilegral("📊 Detalles: " . json_encode([
                            'connection_id' => $connId,
                            'type' => $msgType,
                            'data_length' => strlen($msg)
                        ]));

                        // Lista de tipos que van al SignalServer (chat/llamadas)
                        $chatTypes = [
                            'identify',
                            'auth',
                            'join_chat',
                            'chat_message',
                            'file_upload',
                            'image_upload',
                            'mark_as_read',
                            'typing',
                            'init_call',
                            'call_request',
                            'call_offer',
                            'call_answer',
                            'call_accepted',
                            'call_candidate',
                            'call_ended',
                            'call_reject',
                            'ping',
                            'heartbeat',
                            'get_online_users',
                            'get_user_status'
                        ];

                        if (in_array($msgType, $chatTypes)) {
                            logToFilegral("✅ Enviando a SignalServer (tipo: {$msgType})");
                            $this->chatApp->onMessage($from, $msg);
                        } else {
                            logToFilegral("✅ Enviando a AudioCallServer (tipo: {$msgType})");
                            $this->audioApp->onMessage($from, $msg);
                        }
                    } else {
                        // Si no es JSON, es audio binario → AudioCallServer
                        logToFilegral("🎵 Audio binario → AudioCallServer (" . strlen($msg) . " bytes)");
                        $this->audioApp->onMessage($from, $msg);
                    }
                } catch (\Exception $e) {
                    logToFilegral("❌❌❌ ERROR routing message: " . $e->getMessage());
                    logToFilegral("📂 Archivo: " . $e->getFile() . ":" . $e->getLine());
                    logToFilegral("🧵 Trace: " . $e->getTraceAsString());

                    // Enviar error al cliente
                    try {
                        $from->send(json_encode([
                            'type' => 'server_error',
                            'message' => 'Error procesando mensaje',
                            'error' => $e->getMessage(),
                            'timestamp' => date('Y-m-d H:i:s')
                        ]));
                    } catch (\Exception $sendError) {
                        logToFilegral("❌ No se pudo enviar error al cliente: " . $sendError->getMessage());
                    }
                }
            }

            public function onClose(\Ratchet\ConnectionInterface $conn)
            {
                $connId = $conn->resourceId;
                logToFilegral("❌ Conexión #{$connId} cerrada en servidor unificado");

                try {
                    $this->chatApp->onClose($conn);
                } catch (\Exception $e) {
                    logToFilegral("❌ Error en chatApp->onClose: " . $e->getMessage());
                }

                try {
                    $this->audioApp->onClose($conn);
                } catch (\Exception $e) {
                    logToFilegral("❌ Error en audioApp->onClose: " . $e->getMessage());
                }
            }

            public function onError(\Ratchet\ConnectionInterface $conn, \Exception $e)
            {
                $connId = $conn->resourceId;
                logToFilegral("⚠️ ERROR en conexión #{$connId}: " . $e->getMessage());
                logToFilegral("📂 Archivo: " . $e->getFile() . ":" . $e->getLine());

                try {
                    $this->chatApp->onError($conn, $e);
                } catch (\Exception $chatError) {
                    logToFilegral("❌ Error en chatApp->onError: " . $chatError->getMessage());
                }

                try {
                    $this->audioApp->onError($conn, $e);
                } catch (\Exception $audioError) {
                    logToFilegral("❌ Error en audioApp->onError: " . $audioError->getMessage());
                }
            }
        }
    );

    $httpServer = new \Ratchet\Http\HttpServer($wsServer);
    new \Ratchet\Server\IoServer($httpServer, $webSock, $loop);

    // Timer para notificaciones
    $loop->addPeriodicTimer(2, function () use ($chatApp) {
        logToFilegral("🔍 Verificando notificaciones pendientes...");
        try {
            $chatApp->checkDatabaseNotifications();
        } catch (\Exception $e) {
            logToFilegral("❌ Error en checkDatabaseNotifications: " . $e->getMessage());
        }
    });

    // Timer para limpieza
    $loop->addPeriodicTimer(30, function () use ($chatApp) {
        logToFilegral("🧹 Ejecutando limpieza periódica...");
        try {
            $chatApp->periodicCleanup();
        } catch (\Exception $e) {
            logToFilegral("❌ Error en periodicCleanup: " . $e->getMessage());
        }
    });

    // Timer para estadísticas
    /*  $loop->addPeriodicTimer(60, function () use ($chatApp) {
        logToFilegral("📊 Estadísticas del servidor (cada 60 segundos)");
        try {
            if (method_exists($chatApp, 'getStats')) {
                $stats = $chatApp->getStats();
                logToFilegral("   - Estadísticas: " . json_encode($stats));
            }
        } catch (\Exception $e) {
            logToFile("❌ Error obteniendo estadísticas: " . $e->getMessage());
        }
    });*/

    // Log inicial
    logToFilegral("✅ Servidor WebSocket unificado configurado");
    logToFilegral("📡 Escuchando en: ws://0.0.0.0:9090");
    logToFilegral("🔄 Timer de BD: cada 2 segundos");
    logToFilegral("🧹 Limpieza: cada 30 segundos");
    logToFilegral("📊 Estadísticas: cada 60 segundos");
    logToFilegral("⏰ Iniciado: " . date('Y-m-d H:i:s'));
    logToFilegral("================================================");
    logToFilegral("🟢 Servidor en ejecución (Ctrl+C para detener)");
    logToFilegral("================================================");

    // También mostrar en consola
    echo "\n";
    echo "========================================\n";
    echo "🚀 SERVIDOR WEBSOCKET INICIADO\n";
    echo "========================================\n";
    echo "📡 Escuchando en: ws://0.0.0.0:9090\n";
    echo "📝 Logging en: " . __DIR__ . "/websocket_debug.log\n";
    echo "⏰ Iniciado: " . date('Y-m-d H:i:s') . "\n";
    echo "========================================\n\n";

    $loop->run();
} catch (\Exception $e) {
    $errorMessage = "\n❌❌❌ ERROR CRÍTICO AL INICIAR SERVIDOR ❌❌❌\n";
    $errorMessage .= "Mensaje: " . $e->getMessage() . "\n";
    $errorMessage .= "Archivo: " . $e->getFile() . "\n";
    $errorMessage .= "Línea: " . $e->getLine() . "\n";
    $errorMessage .= "Trace:\n" . $e->getTraceAsString() . "\n";

    // Log al archivo
    logToFilegral($errorMessage);

    // Mostrar en consola
    echo $errorMessage;

    exit(1);
}
