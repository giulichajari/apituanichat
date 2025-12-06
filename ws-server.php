<?php
// ws-server.php - VERSIÓN CON ESTADOS EN TIEMPO REAL

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

// ===================== CONFIGURACIÓN REDIS =====================
use Predis\Client as RedisClient;

class UserStatusManager {
    private $redis;
    private $expireTime = 60; // Segundos de inactividad antes de marcar como offline
    private $cleanupInterval = 300; // Cada 5 minutos limpiar conexiones antiguas
    
    public function __construct() {
        try {
            $this->redis = new RedisClient([
                'scheme' => 'tcp',
                'host'   => '127.0.0.1',
                'port'   => 6379,
                'password' => null, // Cambia si configuraste contraseña
                'database' => 0,
                'timeout' => 2.5
            ]);
            
            // Test de conexión
            $this->redis->ping();
            echo "✅ Redis conectado exitosamente\n";
        } catch (Exception $e) {
            echo "❌ Error Redis: " . $e->getMessage() . "\n";
            $this->redis = null;
        }
    }
    
    // ===================== ESTADOS DE USUARIO =====================
    
    /**
     * Marcar usuario como online
     */
    public function setOnline($userId, $connectionId, $userData = []) {
        if (!$this->redis) return false;
        
        $key = "user:online:{$userId}";
        $connectionKey = "user:connection:{$connectionId}";
        
        // Guardar datos del usuario
        $userData = array_merge([
            'user_id' => $userId,
            'connection_id' => $connectionId,
            'last_seen' => time(),
            'status' => 'online',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ], $userData);
        
        // Guardar usuario con expiración
        $this->redis->hmset($key, $userData);
        $this->redis->expire($key, $this->expireTime);
        
        // Guardar relación conexión -> usuario
        $this->redis->set($connectionKey, $userId);
        $this->redis->expire($connectionKey, $this->expireTime);
        
        // Agregar a lista global de online
        $this->redis->zadd('users:online', time(), $userId);
        
        echo "✅ Usuario {$userId} marcado como ONLINE (conexión: {$connectionId})\n";
        return true;
    }
    
    /**
     * Actualizar tiempo de actividad
     */
    public function updateActivity($userId) {
        if (!$this->redis) return false;
        
        $key = "user:online:{$userId}";
        if ($this->redis->exists($key)) {
            $this->redis->hset($key, 'last_seen', time());
            $this->redis->expire($key, $this->expireTime);
            
            // Actualizar en lista global
            $this->redis->zadd('users:online', time(), $userId);
            
            return true;
        }
        return false;
    }
    
    /**
     * Marcar usuario como offline
     */
    public function setOffline($connectionId, $notify = true) {
        if (!$this->redis) return false;
        
        $connectionKey = "user:connection:{$connectionId}";
        $userId = $this->redis->get($connectionKey);
        
        if (!$userId) {
            return false;
        }
        
        // Obtener datos antes de eliminar
        $userKey = "user:online:{$userId}";
        $userData = $this->redis->hgetall($userKey);
        
        // Eliminar registros
        $this->redis->del($userKey);
        $this->redis->del($connectionKey);
        
        // Remover de lista global (pero mantener historial por 1 hora)
        $this->redis->zrem('users:online', $userId);
        $this->redis->zadd('users:offline:history', time(), $userId);
        $this->redis->expire('users:offline:history', 3600); // 1 hora
        
        echo "✅ Usuario {$userId} marcado como OFFLINE (conexión: {$connectionId})\n";
        
        // Retornar datos para notificación
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
    
    /**
     * Verificar si usuario está online
     */
    public function isOnline($userId) {
        if (!$this->redis) return false;
        
        return $this->redis->exists("user:online:{$userId}");
    }
    
    /**
     * Obtener lista de usuarios online
     */
    public function getOnlineUsers($limit = 100) {
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
    
    /**
     * Obtener datos de usuario específico
     */
    public function getUserStatus($userId) {
        if (!$this->redis) return ['status' => 'offline'];
        
        $key = "user:online:{$userId}";
        if ($this->redis->exists($key)) {
            $data = $this->redis->hgetall($key);
            $data['status'] = 'online';
            $data['online_since'] = $data['last_seen'] ?? time();
            return $data;
        }
        
        // Verificar si estuvo online recientemente
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
    
    /**
     * Obtener estado de múltiples usuarios
     */
    public function getUsersStatus($userIds) {
        if (!$this->redis) return [];
        
        $results = [];
        foreach ($userIds as $userId) {
            $results[$userId] = $this->getUserStatus($userId);
        }
        return $results;
    }
    
    /**
     * Limpiar conexiones antiguas
     */
    public function cleanupStaleConnections() {
        if (!$this->redis) return 0;
        
        $cleaned = 0;
        $onlineUsers = $this->getOnlineUsers(1000);
        $now = time();
        
        foreach ($onlineUsers as $user) {
            $lastSeen = $user['last_seen'] ?? 0;
            if (($now - $lastSeen) > $this->expireTime) {
                // Usuario inactivo por mucho tiempo
                $this->setOffline($user['connection_id'] ?? '', false);
                $cleaned++;
            }
        }
        
        if ($cleaned > 0) {
            echo "🧹 Limpiadas {$cleaned} conexiones inactivas\n";
        }
        
        return $cleaned;
    }
    
    /**
     * Obtener estadísticas
     */
    public function getStats() {
        if (!$this->redis) return [];
        
        $onlineCount = $this->redis->zcard('users:online');
        $connections = count($this->redis->keys("user:connection:*"));
        
        return [
            'online_users' => $onlineCount,
            'active_connections' => $connections,
            'memory_used' => $this->redis->info('memory')['used_memory_human'] ?? '0',
            'uptime' => $this->redis->info('server')['uptime_in_seconds'] ?? 0
        ];
    }
}

// ===================== CLASE DEL SERVIDOR ACTUALIZADA =====================
class SignalServer implements \Ratchet\MessageComponentInterface
{
    protected $clients;
    protected $sessions = []; // chat_id => [conexiones]
    protected $userConnections = []; // user_id => [conexiones]
    protected $statusManager;
    protected $userTimers = []; // Timers por conexión para heartbeat

    public function __construct()
    {
        $this->clients = new \SplObjectStorage();
        $this->statusManager = new UserStatusManager();
        echo "🚀 SignalServer inicializado con Gestor de Estados\n";
    }

    public function onOpen(\Ratchet\ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
        echo date('H:i:s') . " 🔗 Conexión #{$conn->resourceId} abierta\n";

        // Enviar test de conexión
        $conn->send(json_encode([
            'type' => 'welcome',
            'message' => 'WebSocket conectado',
            'connection_id' => $conn->resourceId,
            'server_time' => date('Y-m-d H:i:s'),
            'server_version' => '2.0-con-estados'
        ]));
    }

    public function onClose(\Ratchet\ConnectionInterface $conn)
    {
        // Detener timer de heartbeat si existe
        if (isset($this->userTimers[$conn->resourceId])) {
            $timer = $this->userTimers[$conn->resourceId];
            if ($timer && $timer instanceof \React\EventLoop\TimerInterface) {
                \React\EventLoop\Loop::cancelTimer($timer);
            }
            unset($this->userTimers[$conn->resourceId]);
        }
        
        // Remover de sesiones de chat
        foreach ($this->sessions as $chatId => $connections) {
            if (isset($connections[$conn->resourceId])) {
                unset($this->sessions[$chatId][$conn->resourceId]);
                
                // Notificar a otros en el chat que este usuario se fue
                if (isset($conn->userId)) {
                    $this->notifyUserLeftChat($chatId, $conn->userId);
                }
                
                echo "👋 Removido de chat {$chatId}\n";
            }
        }

        // Remover de conexiones de usuario y marcar como offline
        if (isset($conn->userId)) {
            $userId = $conn->userId;
            
            // Remover de lista local
            if (isset($this->userConnections[$userId])) {
                unset($this->userConnections[$userId][$conn->resourceId]);
                
                // Si no hay más conexiones para este usuario, marcarlo como offline
                if (empty($this->userConnections[$userId])) {
                    unset($this->userConnections[$userId]);
                    echo "👋 Usuario {$userId} sin conexiones activas\n";
                }
            }
            
            // Marcar como offline en Redis y obtener datos para notificar
            $offlineData = $this->statusManager->setOffline($conn->resourceId, true);
            
            if ($offlineData) {
                // Notificar a todos los chats donde esté este usuario
                $this->notifyUserStatusChange($offlineData['user_id'], 'offline', $offlineData);
            }
            
            echo "❌ Usuario {$userId} desconectado (conexión #{$conn->resourceId})\n";
        }

        $this->clients->detach($conn);
        echo date('H:i:s') . " ❌ Conexión #{$conn->resourceId} cerrada\n";
    }

    public function onError(\Ratchet\ConnectionInterface $conn, \Exception $e)
    {
        echo date('H:i:s') . " ⚠️ Error #{$conn->resourceId}: {$e->getMessage()}\n";
        
        // Limpiar estado
        if (isset($this->userTimers[$conn->resourceId])) {
            $timer = $this->userTimers[$conn->resourceId];
            if ($timer && $timer instanceof \React\EventLoop\TimerInterface) {
                \React\EventLoop\Loop::cancelTimer($timer);
            }
            unset($this->userTimers[$conn->resourceId]);
        }
        
        $conn->close();
    }

    public function onMessage(\Ratchet\ConnectionInterface $from, $msg)
    {
        echo date('H:i:s') . " 📨 #{$from->resourceId} → " . substr($msg, 0, 200) . "\n";

        try {
            $data = json_decode($msg, true, 512, JSON_THROW_ON_ERROR);

            if (!isset($data['type'])) {
                echo "❌ Sin tipo de mensaje\n";
                return;
            }

            switch ($data['type']) {
                case 'ping':
                    $this->handlePing($from);
                    break;

                case 'auth':
                    $this->handleAuth($from, $data);
                    break;
                    
                case 'heartbeat':
                    $this->handleHeartbeat($from, $data);
                    break;
                    
                case 'get_online_users':
                    $this->handleGetOnlineUsers($from, $data);
                    break;
                    
                case 'get_user_status':
                    $this->handleGetUserStatus($from, $data);
                    break;
                    
                case 'join_chat':
                    $this->handleJoinChat($from, $data);
                    break;

                case 'chat_message':
                    $this->handleChatMessage($from, $data);
                    break;

                case 'file_upload':
                case 'image_upload':
                case 'file_uploaded':
                case 'image_uploaded':
                    $this->handleFileUpload($from, $data);
                    break;

                case 'test':
                    $this->handleTest($from, $data);
                    break;

                default:
                    echo "⚠️ Tipo desconocido: {$data['type']}\n";
                    $from->send(json_encode([
                        'type' => 'error',
                        'message' => 'Tipo no soportado: ' . $data['type']
                    ]));
            }
        } catch (\JsonException $e) {
            echo "❌ JSON inválido: {$e->getMessage()}\n";
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'JSON inválido'
            ]));
        } catch (\Exception $e) {
            echo "❌ Error: {$e->getMessage()}\n";
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Error interno'
            ]));
        }
    }

    // ===================== NUEVOS HANDLERS PARA ESTADOS =====================
    
    private function handleAuth($from, $data)
    {
        if (!isset($data['user_id'])) {
            $from->send(json_encode([
                'type' => 'auth_error',
                'message' => 'Falta user_id'
            ]));
            return;
        }

        $userId = $data['user_id'];
        $userData = $data['user_data'] ?? [];
        
        // Guardar user_id en conexión
        $from->userId = $userId;
        $from->userData = $userData;

        // Registrar conexión de usuario localmente
        if (!isset($this->userConnections[$userId])) {
            $this->userConnections[$userId] = [];
        }
        $this->userConnections[$userId][$from->resourceId] = $from;

        // Marcar como online en Redis
        $this->statusManager->setOnline($userId, $from->resourceId, $userData);
        
        // Iniciar timer de heartbeat para esta conexión
        $this->startHeartbeatTimer($from);

        echo "🔐 Usuario {$userId} autenticado en conexión #{$from->resourceId}\n";

        // Enviar confirmación
        $from->send(json_encode([
            'type' => 'auth_success',
            'user_id' => $userId,
            'message' => 'Autenticado correctamente',
            'connection_id' => $from->resourceId,
            'online_since' => time()
        ]));
        
        // Notificar a todos que este usuario está online
        $this->notifyUserStatusChange($userId, 'online', [
            'user_id' => $userId,
            'connection_id' => $from->resourceId,
            'user_data' => $userData
        ]);
    }
    
    private function handleHeartbeat($from, $data)
    {
        if (!isset($from->userId)) {
            return;
        }
        
        $userId = $from->userId;
        
        // Actualizar actividad en Redis
        $this->statusManager->updateActivity($userId);
        
        // Responder con pong y estadísticas
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
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Falta user_id'
            ]));
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
    
    // ===================== HANDLERS EXISTENTES ACTUALIZADOS =====================
    
    private function handleJoinChat($from, $data)
    {
        if (!isset($data['chat_id'], $data['user_id'])) {
            $from->send(json_encode(['type' => 'error', 'message' => 'Datos incompletos']));
            return;
        }

        $chatId = $data['chat_id'];
        $userId = $data['user_id'];

        // Inicializar sesión de chat si no existe
        if (!isset($this->sessions[$chatId])) {
            $this->sessions[$chatId] = [];
            echo "💬 Nueva sesión chat {$chatId}\n";
        }

        // Agregar conexión al chat
        $this->sessions[$chatId][$from->resourceId] = $from;
        $from->currentChat = $chatId;

        echo "➕ Usuario {$userId} unido al chat {$chatId}\n";

        // Enviar lista de usuarios online en este chat
        $onlineInChat = $this->getOnlineUsersInChat($chatId);
        
        $from->send(json_encode([
            'type' => 'joined_chat',
            'chat_id' => $chatId,
            'user_id' => $userId,
            'online_count' => count($this->sessions[$chatId]),
            'online_users' => $onlineInChat,
            'timestamp' => time()
        ]));
        
        // Notificar a otros en el chat que este usuario se unió
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
    
    // ===================== MÉTODOS AUXILIARES PARA ESTADOS =====================
    
    private function startHeartbeatTimer($conn)
    {
        if (!isset($conn->userId)) {
            return;
        }
        
        // Cancelar timer anterior si existe
        if (isset($this->userTimers[$conn->resourceId])) {
            $timer = $this->userTimers[$conn->resourceId];
            if ($timer && $timer instanceof \React\EventLoop\TimerInterface) {
                \React\EventLoop\Loop::cancelTimer($timer);
            }
        }
        
        // Crear nuevo timer que envía heartbeat cada 30 segundos
        $timer = \React\EventLoop\Loop::addPeriodicTimer(30, function() use ($conn) {
            if ($conn->userId) {
                $this->statusManager->updateActivity($conn->userId);
                
                // Opcional: Enviar heartbeat al cliente
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
        
        // 1. Enviar a todas las conexiones del usuario (sus otros dispositivos)
        if (isset($this->userConnections[$userId])) {
            foreach ($this->userConnections[$userId] as $conn) {
                try {
                    $conn->send(json_encode($message));
                } catch (\Exception $e) {
                    echo "⚠️ Error enviando status a usuario {$userId}: {$e->getMessage()}\n";
                }
            }
        }
        
        // 2. Enviar a todos los chats donde está el usuario
        foreach ($this->sessions as $chatId => $connections) {
            // Verificar si el usuario está en este chat
            $userInChat = false;
            foreach ($connections as $conn) {
                if (isset($conn->userId) && $conn->userId == $userId) {
                    $userInChat = true;
                    break;
                }
            }
            
            if ($userInChat) {
                // Enviar a todos en el chat excepto al usuario mismo
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
        if (!isset($this->sessions[$chatId])) {
            return;
        }
        
        $userStatus = $this->statusManager->getUserStatus($userId);
        
        $message = [
            'type' => 'user_joined_chat',
            'chat_id' => $chatId,
            'user_id' => $userId,
            'status' => $userStatus,
            'timestamp' => time()
        ];
        
        // Enviar a todos en el chat excepto al que se unió
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
        if (!isset($this->sessions[$chatId])) {
            return;
        }
        
        $message = [
            'type' => 'user_left_chat',
            'chat_id' => $chatId,
            'user_id' => $userId,
            'timestamp' => time()
        ];
        
        // Enviar a todos en el chat excepto al que se fue (ya se fue)
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
    
    // ===================== MÉTODOS EXISTENTES (sin cambios importantes) =====================
    
    private function handleFileUpload($from, $data) {
        // Tu código existente...
        $chatId = $data['chat_id'] ?? null;
        $userId = $data['user_id'] ?? null;
        
        if (!$chatId || !$userId) return;
        
        $broadcastMessage = [
            'type' => $data['type'],
            'chat_id' => $chatId,
            'user_id' => $userId,
            'timestamp' => time()
        ];
        
        // Agregar datos del archivo...
        
        // Enviar a todos en el chat
        $this->broadcastToChat($chatId, $broadcastMessage, $from->resourceId);
    }
    
    private function handleChatMessage($from, $data) {
        // Tu código existente...
        $chatId = $data['chat_id'] ?? null;
        $userId = $data['user_id'] ?? null;
        
        if (!$chatId || !$userId) return;
        
        // ... resto del código
        
        $message = [
            'type' => 'chat_message',
            'chat_id' => $chatId,
            'user_id' => $userId,
            'timestamp' => time()
        ];
        
        $this->broadcastToChat($chatId, $message, $from->resourceId);
    }
    
    private function broadcastToChat($chatId, $message, $excludeConnectionId = null) {
        if (!isset($this->sessions[$chatId])) {
            return 0;
        }
        
        $sentCount = 0;
        foreach ($this->sessions[$chatId] as $conn) {
            if ($excludeConnectionId && $conn->resourceId == $excludeConnectionId) {
                continue;
            }
            
            try {
                $conn->send(json_encode($message));
                $sentCount++;
            } catch (\Exception $e) {
                echo "❌ Error enviando mensaje: {$e->getMessage()}\n";
            }
        }
        
        return $sentCount;
    }
    
    private function handleTest($from, $data) {
        $stats = $this->statusManager->getStats();
        
        $response = [
            'type' => 'test_response',
            'message' => 'WebSocket funcionando con estados en tiempo real',
            'server_time' => date('c'),
            'clients_count' => $this->clients->count(),
            'online_users' => $stats['online_users'] ?? 0,
            'status_manager' => $this->statusManager ? 'active' : 'inactive'
        ];
        
        $from->send(json_encode($response));
        echo "✅ Test respondido con estadísticas\n";
    }
    
    // ===================== MÉTODO PARA VERIFICACIÓN PERIÓDICA =====================
    
    public function checkDatabaseNotifications() {
        // Tu código existente para verificar notificaciones de BD...
        // Mantén este método como está
    }
    
    public function periodicCleanup() {
        // Limpiar conexiones inactivas
        $cleaned = $this->statusManager->cleanupStaleConnections();
        
        // Otras limpiezas periódicas...
        if ($cleaned > 0) {
            echo "🧹 Limpieza periódica: {$cleaned} conexiones limpiadas\n";
        }
        
        // Mostrar estadísticas cada 5 minutos
        static $statsCounter = 0;
        $statsCounter++;
        
        if ($statsCounter >= 10) { // 5 minutos (10 * 30 segundos)
            $stats = $this->statusManager->getStats();
            echo "📊 Estadísticas: " . json_encode($stats) . "\n";
            $statsCounter = 0;
        }
    }
}

// ===================== INICIAR SERVIDOR =====================
echo "\n";
echo "========================================\n";
echo "🚀 INICIANDO SERVIDOR WEBSOCKET CON ESTADOS EN TIEMPO REAL\n";
echo "========================================\n\n";

try {
    // Crear instancia del servidor
    $app = new SignalServer();

    // Usar ReactPHP Event Loop
    $loop = \React\EventLoop\Factory::create();

    // Crear socket WebSocket
    $webSock = new \React\Socket\Server('0.0.0.0:9090', $loop);

    // Crear servidor WebSocket con Ratchet
    $wsServer = new \Ratchet\WebSocket\WsServer($app);
    $httpServer = new \Ratchet\Http\HttpServer($wsServer);

    // Crear IoServer con el loop
    $server = new \Ratchet\Server\IoServer($httpServer, $webSock, $loop);

    // ⭐⭐ TIMER PARA VERIFICAR BD CADA 2 SEGUNDOS
    $loop->addPeriodicTimer(2, function () use ($app) {
        echo date('H:i:s') . " 🔍 Verificando notificaciones en BD...\n";
        $app->checkDatabaseNotifications();
    });

    // ⭐⭐ TIMER PARA LIMPIAR CONEXIONES INACTIVAS CADA 30 SEGUNDOS
    $loop->addPeriodicTimer(30, function () use ($app) {
        $app->periodicCleanup();
    });

    // ⭐⭐ TIMER PARA HEARTBEAT DEL SERVER CADA 60 SEGUNDOS
    $loop->addPeriodicTimer(60, function () {
        echo date('H:i:s') . " 💓 Server heartbeat\n";
    });

    echo "✅ Servidor WebSocket configurado con ReactPHP Loop\n";
    echo "📡 Escuchando en: ws://0.0.0.0:9090\n";
    echo "🔄 Timer de BD: cada 2 segundos\n";
    echo "🧹 Limpieza: cada 30 segundos\n";
    echo "💓 Heartbeat: cada 60 segundos\n";
    echo "⏰ Iniciado: " . date('Y-m-d H:i:s') . "\n";
    echo "========================================\n";
    echo "🟢 Servidor en ejecución (Ctrl+C para detener)\n";
    echo "========================================\n\n";

    // Iniciar el loop
    $loop->run();
} catch (\Exception $e) {
    echo "\n❌❌❌ ERROR CRÍTICO ❌❌❌\n";
    echo "Mensaje: " . $e->getMessage() . "\n";
    echo "Archivo: " . $e->getFile() . "\n";
    echo "Línea: " . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}