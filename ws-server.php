<?php
namespace App\WebSocket;

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

// Crear archivo de log si no existe
if (!file_exists(__DIR__ . '/php_errors.log')) {
    file_put_contents(__DIR__ . '/php_errors.log', '');
}

echo "🔧 Modo DEBUG activado\n";

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use App\Models\ChatModel;
use Exception;

require __DIR__ . '/vendor/autoload.php';

class SignalServer implements MessageComponentInterface
{
    protected \SplObjectStorage $clients;
    protected array $sessions;
    protected ?\PDO $db;
    protected bool $isProduction;
    protected string $serverUrl;

    public function __construct(bool $isProduction = false)
    {
        $this->clients = new \SplObjectStorage();
        $this->sessions = [];
        $this->db = null;
        $this->isProduction = $isProduction;
        $this->serverUrl = $isProduction ? 'wss://tuanichat.com' : 'ws://localhost:8080';
        
        echo "WebSocket server started on {$this->serverUrl}\n";
        $this->log("🚀 Servidor WebSocket iniciado en modo: " . ($isProduction ? 'PRODUCCIÓN' : 'DESARROLLO'));
    }

    // ===================== CONEXIONES =====================
    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
        $this->log("Nueva conexión: {$conn->resourceId} desde " . ($_SERVER['REMOTE_ADDR'] ?? 'IP desconocida'));
        
        // Configurar tiempo de espera para producción
        if ($this->isProduction) {
            $conn->send(json_encode([
                'type' => 'server_info',
                'server_time' => date('c'),
                'ping_interval' => 30
            ]));
        }
    }

    public function onClose(ConnectionInterface $conn)
    {
        foreach ($this->sessions as $chatId => $clients) {
            if ($clients->contains($conn)) {
                $clients->detach($conn);
                $this->log("Cliente {$conn->resourceId} removido del chat {$chatId}");
                if ($clients->count() === 0) {
                    unset($this->sessions[$chatId]);
                    $this->log("Sesión de chat {$chatId} eliminada (sin clientes)");
                }
            }
        }
        $this->clients->detach($conn);
        $this->log("Conexión {$conn->resourceId} cerrada");
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        $this->log("ERROR en conexión {$conn->resourceId}: " . $e->getMessage());
        $conn->close();
    }

    // ===================== MENSAJES =====================
    public function onMessage(ConnectionInterface $from, $msg)
    {
        $this->log("Mensaje recibido de {$from->resourceId} (length: " . strlen($msg) . ")");
        
        try {
            $data = json_decode($msg, true, 512, JSON_THROW_ON_ERROR);
            
            if (!isset($data['type'])) {
                $this->log("ERROR: Campo 'type' no encontrado");
                return;
            }

            // Manejar ping/pong para mantener conexión activa
            if ($data['type'] === 'ping') {
                $from->send(json_encode(['type' => 'pong', 'timestamp' => time()]));
                return;
            }

            switch ($data['type']) {
                case 'auth':
                    $this->handleAuth($from, $data);
                    break;

                case 'join_chat':
                    $this->handleJoinChat($from, $data);
                    break;

                case 'chat_message':
                    $this->handleChatMessage($from, $data);
                    break;

                case 'image':
                case 'file':
                    $this->handleFileUpload($from, $data);
                    break;

                case 'message_read':
                    $this->handleMessageRead($from, $data);
                    break;

                default:
                    $this->log("Tipo de mensaje desconocido: {$data['type']}");
                    $from->send(json_encode([
                        'type' => 'error',
                        'message' => 'Tipo de mensaje no soportado'
                    ]));
                    break;
            }
        } catch (\JsonException $e) {
            $this->log("❌ JSON inválido: " . $e->getMessage());
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Formato JSON inválido'
            ]));
        } catch (\Throwable $e) {
            $this->log("❌ Excepción en onMessage: " . $e->getMessage());
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Error interno del servidor'
            ]));
        }
    }

    // ===================== SUBIDA DE ARCHIVOS =====================
    private function handleFileUpload(ConnectionInterface $from, array $data)
    {
        $this->log("🖼️ Procesando subida de archivo tipo: {$data['type']}");
        
        try {
            // Verificar datos básicos
            if (!isset($data['user_id'], $data['contenido'], $data['file_data'])) {
                $this->log("ERROR: Datos de archivo incompletos");
                return;
            }

            $userId = $data['user_id'];
            $chatId = $data['chat_id'] ?? null;
            $otherUserId = $data['other_user_id'] ?? null;
            $fileData = $data['file_data'];
            $tempId = $data['temp_id'] ?? null;
            
            $this->log("📁 Archivo: " . ($fileData['name'] ?? 'Sin nombre'));
            $this->log("📊 Tamaño: " . ($fileData['size'] ?? 0) . " bytes");

            // Crear instancias
            $chatModel = new ChatModel();
            $fileService = new \App\Services\FileUploadService();

            // Determinar chat real
            $realChatId = $chatId;
            $realOtherUserId = $otherUserId;

            if ($realChatId) {
                if ($chatModel->chatExists($realChatId)) {
                    $this->log("✅ Chat {$realChatId} existe en BD");
                    
                    if (!$realOtherUserId) {
                        $realOtherUserId = $chatModel->getOtherUserFromChat($realChatId, $userId);
                        $this->log("🔍 Obtenido other_user_id del chat: {$realOtherUserId}");
                    }
                } else {
                    $this->log("⚠️ Chat {$realChatId} no existe en BD, interpretando como user_id");
                    $realOtherUserId = $realChatId;
                    $realChatId = $chatModel->findChatBetweenUsers($userId, $realOtherUserId);
                    
                    if (!$realChatId) {
                        $this->log("🆕 Creando nuevo chat entre {$userId} y {$realOtherUserId}");
                        $realChatId = $chatModel->createChat([$userId, $realOtherUserId]);
                    }
                }
            }
            
            if (!$realChatId) {
                throw new Exception("No se pudo determinar el chat para el archivo");
            }

            $this->log("✅ Chat final: {$realChatId}, Other User: {$realOtherUserId}");

            // Crear archivo temporal
            $tmpFilePath = $this->saveTemporaryFile($fileData, $realChatId, $userId);
            
            if (!$tmpFilePath) {
                throw new Exception("Error al crear archivo temporal");
            }

            // Preparar datos para FileUploadService
            $uploadedFile = [
                'name' => $fileData['name'] ?? 'archivo',
                'type' => $fileData['type'] ?? 'application/octet-stream',
                'tmp_name' => $tmpFilePath,
                'error' => 0,
                'size' => $fileData['size'] ?? 0
            ];

            $this->log("📤 Enviando archivo a FileUploadService...");

            // Subir archivo
            $result = $fileService->uploadFileSimple($uploadedFile, $realChatId, $userId);

            // Limpiar archivo temporal
            if (file_exists($tmpFilePath)) {
                unlink($tmpFilePath);
                $this->log("🧹 Archivo temporal eliminado: {$tmpFilePath}");
            }

            if (!$result['success']) {
                throw new Exception("Error subiendo archivo: " . ($result['message'] ?? 'Error desconocido'));
            }

            // Obtener mensaje completo
            $messageId = $result['message_id'] ?? null;
            $fullMessage = $messageId ? $chatModel->getMessageById($messageId) : null;

            if (!$fullMessage && $messageId) {
                throw new Exception("Mensaje no encontrado después de subir archivo");
            }

            // Preparar respuesta
            $response = [
                'type' => $data['type'],
                'message_id' => $messageId,
                'chat_id' => $realChatId,
                'user_id' => $userId,
                'other_user_id' => $realOtherUserId,
                'contenido' => $fullMessage['contenido'] ?? $fileData['name'] ?? 'Archivo',
                'tipo' => $fullMessage['tipo'] ?? ($data['type'] === 'image' ? 'imagen' : 'archivo'),
                'timestamp' => $fullMessage['enviado_en'] ?? date('c'),
                'temp_id' => $tempId,
                'leido' => 0,
                'user_name' => $fullMessage['user_name'] ?? null,
                'file_data' => [
                    'file_url' => $fullMessage['file_url'] ?? $result['file_url'] ?? null,
                    'file_id' => $result['file_id'] ?? null,
                    'file_name' => $fullMessage['file_name'] ?? $result['file_name'] ?? null,
                    'file_original_name' => $fullMessage['file_original_name'] ?? $result['file_original_name'] ?? $fileData['name'],
                    'file_size' => $fullMessage['file_size'] ?? $result['file_size'] ?? 0,
                    'file_mime_type' => $fullMessage['file_mime_type'] ?? $result['file_mime_type'] ?? $fileData['type']
                ]
            ];

            $this->log("✅ Datos preparados para WebSocket");

            // Enviar al chat real
            $sent = $this->emitToChat($realChatId, $data['type'], $response);

            if ($sent) {
                $this->log("📤 Archivo propagado a todos en el chat {$realChatId}");
            } else {
                $this->log("⚠️ No hay otros usuarios en el chat {$realChatId}");
            }

            // Enviar confirmación al remitente
            $from->send(json_encode($response));
            $this->log("✅ Confirmación enviada al remitente {$userId}");

        } catch (Exception $e) {
            $this->log("❌ Error en handleFileUpload: " . $e->getMessage());
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Error subiendo archivo: ' . $e->getMessage(),
                'temp_id' => $tempId ?? null
            ]));
        }
    }

    /**
     * Guardar archivo temporalmente desde base64
     */
    private function saveTemporaryFile(array $fileData, $chatId, $userId)
    {
        if (!isset($fileData['base64'])) {
            throw new Exception("Datos de archivo incompletos (falta base64)");
        }

        $base64Data = $fileData['base64'];
        
        // Validar tamaño en producción
        if ($this->isProduction && strlen($base64Data) > 10 * 1024 * 1024) { // 10MB
            throw new Exception("Archivo demasiado grande. Máximo 10MB");
        }
        
        $fileContent = base64_decode($base64Data);
        if ($fileContent === false) {
            throw new Exception("Error decodificando base64");
        }

        // Crear directorio temporal
        $tmpDir = sys_get_temp_dir() . '/ws_uploads/';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        // Generar nombre de archivo único
        $fileName = uniqid("chat_{$chatId}_{$userId}_") . '.' . $this->getExtensionFromMime($fileData['type'] ?? '');
        $tmpFilePath = $tmpDir . $fileName;

        // Guardar archivo temporal
        if (file_put_contents($tmpFilePath, $fileContent) === false) {
            throw new Exception("Error guardando archivo temporal");
        }

        if (!file_exists($tmpFilePath)) {
            throw new Exception("Archivo temporal no creado");
        }

        $this->log("💾 Archivo temporal guardado: {$tmpFilePath}");

        return $tmpFilePath;
    }

    /**
     * Obtener extensión desde mime type
     */
    private function getExtensionFromMime($mimeType)
    {
        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'text/plain' => 'txt'
        ];

        return $mimeToExt[$mimeType] ?? 'bin';
    }

    // ===================== AUTENTICACIÓN =====================
    private function handleAuth(ConnectionInterface $from, array $data)
    {
        if (!isset($data['user_id'], $data['token'])) {
            $this->log("ERROR: Datos de autenticación incompletos");
            return;
        }

        // Aquí deberías validar el token con tu sistema de autenticación
        $userId = (int)$data['user_id'];
        $token = $data['token'];
        
        // Ejemplo de validación básica
        $isValid = $this->validateToken($userId, $token);
        
        if (!$isValid) {
            $this->log("⚠️ Autenticación fallida para usuario {$userId}");
            $from->send(json_encode([
                'type' => 'auth_failed',
                'message' => 'Token inválido'
            ]));
            $from->close();
            return;
        }

        $from->userId = $userId;
        $from->authenticated = true;
        
        $this->log("✅ Usuario {$from->userId} autenticado en conexión {$from->resourceId}");

        $from->send(json_encode([
            'type' => 'auth_success',
            'user_id' => $from->userId,
            'message' => 'Autenticación exitosa',
            'server_time' => date('c')
        ]));
    }

    private function validateToken($userId, $token): bool
    {
        // Implementa tu lógica de validación de token aquí
        // Por ahora, retorna true para desarrollo
        if (!$this->isProduction) {
            return true;
        }
        
        // En producción, valida contra tu base de datos o sistema de autenticación
        try {
            $chatModel = new ChatModel();
            return $chatModel->validateUserToken($userId, $token);
        } catch (Exception $e) {
            $this->log("❌ Error validando token: " . $e->getMessage());
            return false;
        }
    }

    // ===================== UNIÓN A CHAT =====================
    private function handleJoinChat(ConnectionInterface $from, array $data)
    {
        if (!isset($data['chat_id'], $data['user_id'])) {
            $this->log("ERROR: Datos de join_chat incompletos");
            return;
        }

        // Verificar que el usuario esté autenticado
        if (!isset($from->authenticated) || !$from->authenticated) {
            $this->log("⚠️ Intento de unirse a chat sin autenticar: {$from->resourceId}");
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'No autenticado'
            ]));
            return;
        }

        $chatId = $data['chat_id'];
        $userId = $data['user_id'];

        // Verificar que el usuario pertenezca al chat
        $chatModel = new ChatModel();
        if (!$chatModel->isUserInChat($userId, $chatId)) {
            $this->log("⚠️ Usuario {$userId} no pertenece al chat {$chatId}");
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'No tienes acceso a este chat'
            ]));
            return;
        }

        if (!isset($this->sessions[$chatId])) {
            $this->sessions[$chatId] = new \SplObjectStorage();
            $this->log("Sesión de chat {$chatId} creada");
        }

        if (!$this->sessions[$chatId]->contains($from)) {
            $this->sessions[$chatId]->attach($from);
            $from->currentChat = $chatId;
            $this->log("Cliente {$from->resourceId} (usuario {$userId}) agregado al chat {$chatId}");
        }

        // Notificar a otros usuarios en el chat (opcional)
        $this->notifyUserJoined($chatId, $userId, $from->resourceId);

        $from->send(json_encode([
            'type' => 'joined_chat',
            'chat_id' => $chatId,
            'user_id' => $userId,
            'message' => 'Unido al chat exitosamente',
            'online_count' => $this->sessions[$chatId]->count()
        ]));
    }

    private function notifyUserJoined($chatId, $userId, $connectionId)
    {
        $notification = [
            'type' => 'user_joined',
            'chat_id' => $chatId,
            'user_id' => $userId,
            'connection_id' => $connectionId,
            'timestamp' => date('c')
        ];

        $this->emitToChat($chatId, 'user_joined', $notification, $userId);
    }

    // ===================== MENSAJES DE CHAT =====================
    private function handleChatMessage(ConnectionInterface $from, array $data)
    {
        try {
            // Verificar autenticación
            if (!isset($from->authenticated) || !$from->authenticated) {
                $this->log("⚠️ Intento de enviar mensaje sin autenticar");
                return;
            }

            // Verificar datos
            if (!isset($data['chat_id'], $data['user_id'], $data['contenido'])) {
                $this->log("ERROR: Datos incompletos");
                return;
            }

            $receivedIdentifier = $data['chat_id'];
            $userId = $data['user_id'];
            $otherUserId = $data['other_user_id'] ?? null;

            $chatModel = new ChatModel();

            // Determinar el CHAT_ID REAL
            $realChatId = null;

            if ($chatModel->chatExists($receivedIdentifier)) {
                $realChatId = $receivedIdentifier;
                $this->log("✅ {$receivedIdentifier} es un chat_id válido");
            } else {
                $this->log("🔍 {$receivedIdentifier} no es chat_id. Buscando chat entre usuarios...");

                if (!$otherUserId) {
                    $otherUserId = $receivedIdentifier;
                }

                $existingChat = $chatModel->findChatBetweenUsers($userId, $otherUserId);

                if ($existingChat) {
                    $realChatId = $existingChat;
                    $this->log("✅ Chat existente encontrado: {$realChatId}");
                } else {
                    $this->log("🆕 Creando nuevo chat entre {$userId} y {$otherUserId}");
                    $realChatId = $chatModel->createChat([$userId, (int)$otherUserId]);
                    $this->log("✅ Chat creado: {$realChatId}");
                }
            }

            if (!$realChatId) {
                throw new Exception("No se pudo determinar el chat_id real");
            }

            // Enviar mensaje
            $messageId = $chatModel->sendMessage(
                $realChatId,
                $userId,
                $data['contenido'],
                $data['tipo'] ?? 'texto',
                null,
                $otherUserId
            );

            // Obtener mensaje completo
            $fullMessage = $chatModel->getMessageById($messageId);

            // Preparar respuesta
            $response = [
                'type' => 'chat_message',
                'message_id' => $messageId,
                'chat_id' => $realChatId,
                'user_id' => $userId,
                'other_user_id' => $otherUserId,
                'contenido' => $data['contenido'],
                'tipo' => $data['tipo'] ?? 'texto',
                'timestamp' => date('c'),
                'temp_id' => $data['temp_id'] ?? null,
                'leido' => 0,
                'user_name' => $fullMessage['user_name'] ?? null
            ];

            $this->log("📤 Enviando al CHAT REAL {$realChatId}");

            // Enviar al chat real
            $this->emitToChat($realChatId, 'chat_message', $response);

            // También enviar al remitente
            $from->send(json_encode($response));

            $this->log("✅ Mensaje enviado correctamente al chat {$realChatId}");

        } catch (Exception $e) {
            $this->log("❌ Error: " . $e->getMessage());
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Error: ' . $e->getMessage()
            ]));
        }
    }

    // ===================== MENSAJE LEÍDO =====================
    private function handleMessageRead(ConnectionInterface $from, array $data)
    {
        if (!isset($data['message_id'], $data['user_id'], $data['chat_id'])) {
            $this->log("ERROR: Datos de message_read incompletos");
            return;
        }

        // Actualizar en BD
        $chatModel = new ChatModel();
        $chatModel->markMessageAsRead($data['message_id'], $data['user_id']);

        $this->emitToChat(
            $data['chat_id'],
            'message_read',
            [
                'message_id' => $data['message_id'],
                'read_by' => $data['user_id'],
                'read_at' => date('c')
            ],
            $data['user_id']
        );

        $this->log("Mensaje {$data['message_id']} leído por usuario {$data['user_id']} en chat {$data['chat_id']}");
    }

    // ===================== EMITIR A CHAT =====================
    public function emitToChat($chatId, $eventType, $data, $excludeUserId = null)
    {
        if (!isset($this->sessions[$chatId])) {
            $this->log("⚠️ Chat {$chatId} no encontrado para emitir evento");
            return false;
        }

        $messageData = $data;
        $messageData['type'] = $eventType;

        $sentCount = 0;
        foreach ($this->sessions[$chatId] as $client) {
            // Excluir usuario si se especifica
            if ($excludeUserId && isset($client->userId) && $client->userId == $excludeUserId) {
                continue;
            }

            try {
                $client->send(json_encode($messageData));
                $sentCount++;
                $this->log("   ✅ Enviado a cliente {$client->resourceId} (usuario: " . ($client->userId ?? 'unknown') . ")");
            } catch (Exception $e) {
                $this->log("   ❌ Error enviando a cliente {$client->resourceId}: " . $e->getMessage());
            }
        }

        $this->log("📤 Evento '{$eventType}' enviado a {$sentCount} cliente(s) en chat {$chatId}");
        return $sentCount > 0;
    }

    // ===================== LOGGING =====================
    private function log(string $message)
    {
        $timestamp = date('Y-m-d H:i:s');
        $line = "{$timestamp} | {$message}\n";
        
        // Log en archivo
        $logDir = __DIR__ . '/logs/';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $logFile = $logDir . 'websocket-' . date('Y-m-d') . '.log';
        file_put_contents($logFile, $line, FILE_APPEND);
        
        // También mostrar en consola en desarrollo
        if (!$this->isProduction) {
            echo $line;
        }
    }
}

// ===================== INICIO DEL SERVIDOR =====================

// Determinar si estamos en producción
$isProduction = (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] === 'tuanichat.com');

// Configuración para producción con SSL
if ($isProduction) {
    // Ruta a tus certificados SSL
    $sslContext = [
        'ssl' => [
            'local_cert' => '/etc/letsencrypt/live/tuanichat.com/fullchain.pem',
            'local_pk' => '/etc/letsencrypt/live/tuanichat.com/privkey.pem',
            'allow_self_signed' => false,
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ];
    
    // Crear servidor con SSL
    $loop = React\EventLoop\Factory::create();
    $webSocket = new Ratchet\WebSocket\WsServer(new SignalServer(true));
    $webSocket->enableKeepAlive($loop, 30); // Ping cada 30 segundos
    
    $server = new Ratchet\Server\IoServer(
        new Ratchet\Http\HttpServer($webSocket),
        new React\Socket\SocketServer('0.0.0.0:8080', $sslContext, $loop),
        $loop
    );
    
    echo "🚀 Servidor WebSocket SSL iniciado en wss://tuanichat.com:8080\n";
    
} else {
    // Desarrollo - sin SSL
    $server = Ratchet\Server\IoServer::factory(
        new Ratchet\Http\HttpServer(
            new Ratchet\WebSocket\WsServer(
                new SignalServer(false)
            )
        ),
        8080
    );
    
    echo "🚀 Servidor WebSocket iniciado en ws://localhost:8080\n";
}

$server->run();