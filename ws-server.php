<?php
// ws-server.php - VERSIÓN 100% FUNCIONAL

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

// ===================== VERIFICAR CLASES RATCHET =====================
echo "🔍 Verificando clases Ratchet...\n";

$requiredClasses = [
    'Ratchet\MessageComponentInterface',
    'Ratchet\ConnectionInterface',
    'Ratchet\Server\IoServer',
    'Ratchet\Http\HttpServer',
    'Ratchet\WebSocket\WsServer',
    'React\EventLoop\Factory',
    'React\Socket\Server'
];

foreach ($requiredClasses as $class) {
    if (class_exists($class)) {
        echo "✅ $class\n";
    } else {
        echo "❌ $class - NO encontrada\n";
    }
}

use App\Models\ChatModel;
// ===================== CLASE DEL SERVIDOR =====================
class SignalServer implements \Ratchet\MessageComponentInterface
{
    protected $clients;
    protected $sessions = []; // chat_id => [conexiones]
    protected $userConnections = []; // user_id => [conexiones]

    public function __construct()
    {
        $this->clients = new \SplObjectStorage();
        echo "🚀 SignalServer inicializado\n";
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
            'server_time' => date('Y-m-d H:i:s')
        ]));
    }

    public function onClose(\Ratchet\ConnectionInterface $conn)
    {
        // Remover de sesiones de chat
        foreach ($this->sessions as $chatId => $connections) {
            if (isset($connections[$conn->resourceId])) {
                unset($this->sessions[$chatId][$conn->resourceId]);
                echo "👋 Removido de chat {$chatId}\n";
            }
        }

        // Remover de conexiones de usuario
        foreach ($this->userConnections as $userId => $connections) {
            if (isset($connections[$conn->resourceId])) {
                unset($this->userConnections[$userId][$conn->resourceId]);
                echo "👋 Removido conexiones usuario {$userId}\n";
            }
        }

        $this->clients->detach($conn);
        echo date('H:i:s') . " ❌ Conexión #{$conn->resourceId} cerrada\n";
    }

    public function onError(\Ratchet\ConnectionInterface $conn, \Exception $e)
    {
        echo date('H:i:s') . " ⚠️ Error #{$conn->resourceId}: {$e->getMessage()}\n";
        $conn->close();
    }

    public function onMessage(\Ratchet\ConnectionInterface $from, $msg)
    {
        echo date('H:i:s') . " 📨 #{$from->resourceId} → " . substr($msg, 0, 200) . "\n";

        // ⭐⭐ GUARDAR LOG COMPLETO DEL MENSAJE RECIBIDO ⭐⭐
        $this->logToFile("📨 Mensaje RAW recibido: " . $msg);

        try {
            $data = json_decode($msg, true, 512, JSON_THROW_ON_ERROR);

            // ⭐⭐ GUARDAR LOG DEL DATA DECODIFICADO ⭐⭐
            $this->logToFile("📋 Data decodificado: " . json_encode($data, JSON_PRETTY_PRINT));

            if (!isset($data['type'])) {
                echo "❌ Sin tipo de mensaje\n";
                $this->logToFile("❌ ERROR: Mensaje sin tipo");
                return;
            }

            // ⭐⭐ GUARDAR LOG DEL TIPO RECIBIDO ⭐⭐
            $this->logToFile("🎯 Tipo recibido: " . $data['type']);

            switch ($data['type']) {
                case 'ping':
                    $this->logToFile("🔄 Caso: ping");
                    $this->handlePing($from);
                    break;

                case 'auth':
                    $this->logToFile("🔄 Caso: auth");
                    $this->handleAuth($from, $data);
                    break;

                case 'join_chat':
                    $this->logToFile("🔄 Caso: join_chat");
                    $this->handleJoinChat($from, $data);
                    break;

                case 'chat_message':
                    $this->logToFile("🔄 Caso: chat_message");
                    $this->handleChatMessage($from, $data);
                    break;

                case 'file_upload':
                    $this->logToFile("🔄 Caso: " . $data['type'] . " (manejado como file_upload)");
                    $this->handleFileUpload($from, $data);
                    break;
                case 'image_upload':
                    $this->logToFile("🔄 Caso: " . $data['type'] . " (manejado como file_upload)");
                    $this->handleFileUpload($from, $data);
                    break;

                case 'file_uploaded': // ⭐⭐ NUEVO: Agregar este caso
                case 'image_uploaded': // ⭐⭐ NUEVO: Agregar este caso
                    $this->logToFile("🔄 Caso: " . $data['type'] . " (manejado como file_upload)");
                    $this->handleFileUpload($from, $data);
                    break;

                case 'test':
                    $this->logToFile("🔄 Caso: test");
                    $this->handleTest($from, $data);
                    break;

                default:
                    echo "⚠️ Tipo desconocido: {$data['type']}\n";
                    $this->logToFile("⚠️ Tipo desconocido: " . $data['type']);
                    $from->send(json_encode([
                        'type' => 'error',
                        'message' => 'Tipo no soportado: ' . $data['type']
                    ]));
            }
        } catch (\JsonException $e) {
            echo "❌ JSON inválido: {$e->getMessage()}\n";
            $this->logToFile("❌ JSON inválido: " . $e->getMessage());
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'JSON inválido'
            ]));
        } catch (\Exception $e) {
            echo "❌ Error: {$e->getMessage()}\n";
            $this->logToFile("❌ Error general: " . $e->getMessage());
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Error interno'
            ]));
        }
    }

    // ===================== HANDLERS =====================


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

        // ⭐⭐ PREPARAR MENSAJE PARA BROADCAST (A TODOS INCLUYENDO REMITENTE)
        $broadcastMessage = [
            'type' => $data['type'], // 'image_upload' o 'file_upload'
            'message_id' => $data['message_id'] ?? uniqid(),
            'chat_id' => $chatId,
            'user_id' => $userId,
            'contenido' => $data['contenido'] ?? 'Archivo',
            'tipo' => $data['tipo'] ?? 'archivo',
            'timestamp' => $data['timestamp'] ?? date('c'),
            'leido' => 0,
            'status' => 'delivered'
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

        // ⭐⭐ ENVIAR A TODOS EN EL CHAT (INCLUYENDO AL REMITENTE)
        $sentCount = 0;
        if (isset($this->sessions[$chatId])) {
            foreach ($this->sessions[$chatId] as $client) {
                try {
                    $client->send(json_encode($broadcastMessage));
                    $sentCount++;
                    $this->logToFile("✅ Enviado a cliente");
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

        $this->logToFile("📤 Mensaje de archivo enviado a {$sentCount} cliente(s) en chat {$chatId}");
    }

    // En la clase SignalServer, agrega:
    public function checkDatabaseNotifications()
    {
        try {
            $this->logToFile("🔍 Verificando notificaciones en BD...");

            // ⭐⭐ USAR TUS CREDENCIALES REALES DE Database.php
            $host = "localhost";
            $dbname = "tuanichatbd";
            $username = "tuanichat";
            $password = "Argentina1991!";

            // Conectar a BD usando las mismas credenciales que tu app
            $pdo = new \PDO(
                "mysql:host=$host;dbname=$dbname;charset=utf8",
                $username,
                $password,
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES => false
                ]
            );

            $this->logToFile("✅ Conectado a BD: $dbname");

            // Contar notificaciones pendientes
            $countStmt = $pdo->prepare("SELECT COUNT(*) as count FROM websocket_notifications WHERE status = 'pending'");
            $countStmt->execute();
            $count = $countStmt->fetch()['count'];

            $this->logToFile("📊 Notificaciones pendientes: $count");

            if ($count == 0) {
                $this->logToFile("ℹ️ No hay notificaciones pendientes");
                return;
            }

            // Obtener notificaciones (limit 10 para no saturar)
            $stmt = $pdo->prepare("
            SELECT id, chat_id, message_data 
            FROM websocket_notifications 
            WHERE status = 'pending' 
            ORDER BY created_at ASC 
            LIMIT 10
        ");

            $stmt->execute();
            $notifications = $stmt->fetchAll();

            $this->logToFile("📦 Encontradas " . count($notifications) . " notificaciones");

            $processedCount = 0;
            foreach ($notifications as $notification) {
                $this->logToFile("🔄 Procesando notificación ID: {$notification['id']}, Chat: {$notification['chat_id']}");

                // Decodificar JSON
                $messageData = json_decode($notification['message_data'], true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->logToFile("❌ JSON inválido en notificación {$notification['id']}");

                    // Marcar como fallida
                    $updateStmt = $pdo->prepare("UPDATE websocket_notifications SET status = 'failed' WHERE id = ?");
                    $updateStmt->execute([$notification['id']]);
                    continue;
                }

                if (!$messageData || !isset($messageData['chat_id'])) {
                    $this->logToFile("❌ Datos inválidos en notificación {$notification['id']}");
                    continue;
                }

                // Marcar como procesando
                $updateStmt = $pdo->prepare(
                    "UPDATE websocket_notifications 
                 SET status = 'processing', processed_at = NOW() 
                 WHERE id = ?"
                );
                $updateStmt->execute([$notification['id']]);

                // ⭐⭐ ENVIAR A CLIENTES CONECTADOS
                $sentCount = $this->broadcastToChat($messageData['chat_id'], $messageData);

                // Marcar como enviado
                $updateStmt = $pdo->prepare(
                    "UPDATE websocket_notifications SET status = 'sent' WHERE id = ?"
                );
                $updateStmt->execute([$notification['id']]);

                $this->logToFile("✅ Notificación {$notification['id']} enviada a {$sentCount} cliente(s)");
                $processedCount++;
            }

            if ($processedCount > 0) {
                $this->logToFile("📊 Total procesadas: {$processedCount} notificaciones");
                echo date('H:i:s') . " 📊 Procesadas {$processedCount} notificaciones\n";
            }

            $pdo = null; // Cerrar conexión

        } catch (\PDOException $e) {
            $this->logToFile("❌ Error PDO: " . $e->getMessage() . " (Code: " . $e->getCode() . ")");
            echo date('H:i:s') . " ❌ Error BD: " . $e->getMessage() . "\n";
        } catch (\Exception $e) {
            $this->logToFile("❌ Error general: " . $e->getMessage());
            echo date('H:i:s') . " ❌ Error: " . $e->getMessage() . "\n";
        }
    }


    public function broadcastToChat($chatId, $messageData)
    {
        $this->logToFile("📢 Broadcast externo a chat {$chatId}");

        if (!isset($this->sessions[$chatId])) {
            $this->logToFile("⚠️ No hay sesiones activas para chat {$chatId}");
            return 0;
        }

        $sentCount = 0;
        foreach ($this->sessions[$chatId] as $client) {
            try {
                $client->send(json_encode($messageData));
                $sentCount++;
            } catch (\Exception $e) {
                $this->logToFile("❌ Error en broadcast: " . $e->getMessage());
            }
        }

        $this->logToFile("✅ Broadcast enviado a {$sentCount} cliente(s)");
        return $sentCount;
    }
    private function handlePing($from)
    {
        $from->send(json_encode([
            'type' => 'pong',
            'timestamp' => time(),
            'server_time' => date('H:i:s')
        ]));
        echo "🏓 Ping respondido\n";
    }

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
        $from->userId = $userId;

        // Registrar conexión de usuario
        if (!isset($this->userConnections[$userId])) {
            $this->userConnections[$userId] = [];
        }
        $this->userConnections[$userId][$from->resourceId] = $from;

        echo "🔐 Usuario {$userId} autenticado en conexión #{$from->resourceId}\n";

        $from->send(json_encode([
            'type' => 'auth_success',
            'user_id' => $userId,
            'message' => 'Autenticado correctamente',
            'connection_id' => $from->resourceId
        ]));
    }

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

        $from->send(json_encode([
            'type' => 'joined_chat',
            'chat_id' => $chatId,
            'user_id' => $userId,
            'online_count' => count($this->sessions[$chatId])
        ]));
    }

    private function handleChatMessage($from, $data)
    {
        $this->logToFile("💭 Procesando mensaje de chat");

        $chatId = $data['chat_id'] ?? null;
        $userId = $data['user_id'] ?? null;
        $content = $data['contenido'] ?? '';
        $tempId = $data['temp_id'] ?? null;

        if (!$chatId || !$userId) {
            $this->logToFile("❌ Datos incompletos: chat_id=$chatId, user_id=$userId");
            return;
        }

        $this->logToFile("📝 Chat: {$chatId}, User: {$userId}, Content: " . substr($content, 0, 50));

        // 1. Confirmación inmediata
        if ($tempId) {
            $from->send(json_encode([
                'type' => 'message_ack',
                'temp_id' => $tempId,
                'status' => 'received',
                'timestamp' => time()
            ]));
            $this->logToFile("✅ ACK enviado para temp_id: $tempId");
        }

        // 2. Intentar guardar en BD
        $messageId = null;
        try {
            $this->logToFile("🔄 Intentando crear ChatModel...");

            // Asegúrate de que la clase existe
            if (!class_exists('App\Models\ChatModel')) {
                throw new Exception("Clase ChatModel no encontrada");
            }

            $chatModel = new App\Models\ChatModel();
            $this->logToFile("✅ ChatModel creado");

            // Verificar si el chat existe
            if (!$chatModel->chatExists($chatId)) {
                $this->logToFile("⚠️ Chat $chatId no existe, buscando por usuarios...");

                $otherUserId = $data['other_user_id'] ?? $chatId;
                $realChatId = $chatModel->findChatBetweenUsers($userId, $otherUserId);

                if (!$realChatId) {
                    $this->logToFile("🆕 Creando nuevo chat entre $userId y $otherUserId");
                    $realChatId = $chatModel->createChat([$userId, $otherUserId]);
                    $this->logToFile("✅ Chat creado: {$realChatId}");
                }

                $chatId = $realChatId;
            }

            $this->logToFile("💾 Guardando mensaje en BD...");

            // Guardar mensaje
            $messageId = $chatModel->sendMessage(
                $chatId,
                $userId,
                $content,
                $data['tipo'] ?? 'texto'
            );

            $this->logToFile("✅ Mensaje guardado en BD: ID {$messageId}");
        } catch (\Exception $e) {
            $errorMsg = "❌ Error BD: " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine();
            $this->logToFile($errorMsg);
            $messageId = 'temp_' . rand(1000, 9999);
        }

        // 3. Preparar respuesta
        $response = [
            'type' => 'chat_message',
            'message_id' => $messageId,
            'chat_id' => $chatId,
            'user_id' => $userId,
            'contenido' => $content,
            'tipo' => $data['tipo'] ?? 'texto',
            'timestamp' => date('c'),
            'temp_id' => $tempId,
            'leido' => 0,
            'user_name' => $data['user_name'] ?? 'Usuario',
            'status' => 'sent'
        ];

        // 4. Enviar a todos en el chat (INCLUYENDO al remitente)
        $sentCount = 0;
        if (isset($this->sessions[$chatId])) {
            foreach ($this->sessions[$chatId] as $client) {
                try {
                    $client->send(json_encode($response));
                    $sentCount++;
                } catch (\Exception $e) {
                    $this->logToFile("❌ Error enviando a cliente: {$e->getMessage()}");
                }
            }
        } else {
            $this->logToFile("⚠️ No hay sesiones activas para chat $chatId");

            // Si no hay sesión, al menos enviar al remitente
            $from->send(json_encode($response));
            $sentCount = 1;
        }

        // 5. ⚠️ REMOVI ESTA LÍNEA - NO la necesitas
        // $from->send(json_encode($response));

        $this->logToFile("📤 Mensaje enviado a {$sentCount} cliente(s) en chat {$chatId}");
    }
    // REEMPLAZA todos los error_log() con esto:
    private function logToFile($message)
    {
        $logFile = __DIR__ . '/websocket_debug.log';
        $timestamp = date('Y-m-d H:i:s');
        $formattedMessage = "[$timestamp] " . $message . "\n";

        // Escribir directamente en archivo
        file_put_contents($logFile, $formattedMessage, FILE_APPEND | LOCK_EX);

        // También mostrar por consola si está disponible
        if (php_sapi_name() === 'cli') {
            echo $formattedMessage;
        }
    }
    private function handleTest($from, $data)
    {
        echo "🧪 Test recibido\n";

        $response = [
            'type' => 'test_response',
            'message' => 'WebSocket funcionando correctamente',
            'received_data' => $data,
            'server_time' => date('c'),
            'clients_count' => $this->clients->count()
        ];

        $from->send(json_encode($response));

        echo "✅ Test respondido\n";
    }
}

// ===================== INICIAR SERVIDOR =====================
echo "\n";
echo "========================================\n";
echo "🚀 INICIANDO SERVIDOR WEBSOCKET\n";
echo "========================================\n\n";

// ===================== INICIAR SERVIDOR =====================
echo "\n";
echo "========================================\n";
echo "🚀 INICIANDO SERVIDOR WEBSOCKET CON DATABASE CHECKER\n";
echo "========================================\n\n";

try {
    // Crear instancia del servidor
    $app = new SignalServer();

    // ⭐⭐ IMPORTANTE: Usar ReactPHP Event Loop
    $loop = \React\EventLoop\Factory::create();

    // Crear socket WebSocket
    $webSock = new \React\Socket\Server('0.0.0.0:9090', $loop);

    // Crear servidor WebSocket con Ratchet
    $wsServer = new \Ratchet\WebSocket\WsServer($app);
    $httpServer = new \Ratchet\Http\HttpServer($wsServer);

    // Crear IoServer con el loop
    $server = new \Ratchet\Server\IoServer($httpServer, $webSock, $loop);

    // ⭐⭐ AGREGAR TIMER PARA VERIFICAR BD CADA 2 SEGUNDOS
    $loop->addPeriodicTimer(2, function () use ($app) {
        echo date('H:i:s') . " 🔍 Verificando notificaciones en BD...\n";
        $app->checkDatabaseNotifications();
    });

    // ⭐⭐ AGREGAR TIMER PARA LIMPIAR LOGS CADA 30 SEGUNDOS
    $loop->addPeriodicTimer(30, function () {
        $logFile = __DIR__ . '/websocket_debug.log';
        if (file_exists($logFile) && filesize($logFile) > 10 * 1024 * 1024) { // 10MB
            file_put_contents($logFile, "=== LOG ROTATED AT " . date('Y-m-d H:i:s') . " ===\n");
            echo date('H:i:s') . " 🔄 Log rotado (demasiado grande)\n";
        }
    });

    echo "✅ Servidor WebSocket configurado con ReactPHP Loop\n";
    echo "📡 Escuchando en: ws://0.0.0.0:9090\n";
    echo "⏰ Timer de BD activado: cada 2 segundos\n";
    echo "⏰ Iniciado: " . date('Y-m-d H:i:s') . "\n";
    echo "========================================\n";
    echo "🟢 Servidor en ejecución (Ctrl+C para detener)\n";
    echo "========================================\n\n";

    // Iniciar el loop (esto mantiene el servidor corriendo)
    $loop->run();
} catch (\Exception $e) {
    echo "\n❌❌❌ ERROR CRÍTICO ❌❌❌\n";
    echo "Mensaje: " . $e->getMessage() . "\n";
    echo "Archivo: " . $e->getFile() . "\n";
    echo "Línea: " . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
