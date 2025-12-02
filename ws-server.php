<?php

namespace App\WebSocket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use App\Models\ChatModel; // <-- Asegurate de que ChatModel tenga namespace App\Models
use Exception;

require __DIR__ . '/vendor/autoload.php';

class SignalServer implements MessageComponentInterface
{
    protected \SplObjectStorage $clients;
    protected array $sessions; // chat_id => SplObjectStorage de conexiones
    protected ?\PDO $db; // Conexión a BD opcional si ChatModel la necesita

    public function __construct()
    {
        $this->clients = new \SplObjectStorage();
        $this->sessions = [];
        $this->db = null; // Opcional: si necesitas pasar la BD a ChatModel
        echo "WebSocket server started on ws://localhost:8080\n";
    }

    // ===================== CONEXIONES =====================
    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
        $this->log("Nueva conexión: {$conn->resourceId}");
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
    $this->log("Mensaje recibido en onmessage (raw): $msg");

    try {
        $data = json_decode($msg, true);
        if (!$data || !is_array($data)) {
            $this->log("ERROR: JSON inválido");
            return;
        }

        if (!isset($data['type'])) {
            $this->log("ERROR: Campo 'type' no encontrado en: " . json_encode($data));
            return;
        }

        // En onMessage, modificar el switch case:
switch ($data['type']) {
    case 'auth':
        $this->handleAuth($from, $data);
        break;

    case 'join_chat':
        $this->handleJoinChat($from, $data);
        break;

    case 'chat_message':
        $this->log("Dispatching handleChatMessage...");
        try {
            $this->handleChatMessage($from, $data);
        } catch (\Throwable $e) {
            $this->log("❌ Excepción en handleChatMessage: " . $e->getMessage());
        }
        break;

    case 'image':
    case 'file':
        // ✅ Mismo handler para ambos tipos de archivo
        $this->log("📁 Procesando archivo tipo: {$data['type']}");
        try {
            $this->handleFileUpload($from, $data);
        } catch (\Throwable $e) {
            $this->log("❌ Excepción en handleFileUpload: " . $e->getMessage());
        }
        break;

    case 'message_read':
        $this->handleMessageRead($from, $data);
        break;

    default:
        $this->log("Tipo de mensaje desconocido: {$data['type']}");
        break;
}
    } catch (\Throwable $e) {
        $this->log("❌ Excepción en onMessage: " . $e->getMessage());
    }
}

// ===================== SUBIDA DE ARCHIVOS - VERSIÓN CORREGIDA =====================
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
        $this->log("🎯 Chat ID: {$chatId}, Other User ID: {$otherUserId}");

        // Crear instancias
        $chatModel = new ChatModel();
        $fileService = new \App\Services\FileUploadService();

        // ✅ PASO 1: DETERMINAR EL CHAT_ID REAL Y OTHER_USER_ID
        $realChatId = $chatId;
        $realOtherUserId = $otherUserId;

        // Si tenemos chatId, verificar que exista y obtener el other_user_id
        if ($realChatId) {
            if ($chatModel->chatExists($realChatId)) {
                $this->log("✅ Chat {$realChatId} existe en BD");
                
                // Obtener el other_user_id del chat
                if (!$realOtherUserId) {
                    $realOtherUserId = $chatModel->getOtherUserFromChat($realChatId, $userId);
                    $this->log("🔍 Obtenido other_user_id del chat: {$realOtherUserId}");
                }
            } else {
                // chat_id no existe en BD, probablemente es un user_id
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

        // ✅ PASO 2: CREAR ARCHIVO TEMPORAL
$tmpFilePath = $this->saveTemporaryFile($fileData, $realChatId, $userId);
        
        if (!$tmpFilePath) {
            throw new Exception("Error al crear archivo temporal");
        }

        // ✅ PASO 3: PREPARAR DATOS PARA FileUploadService
        $uploadedFile = [
            'name' => $fileData['name'] ?? 'archivo',
            'type' => $fileData['type'] ?? 'application/octet-stream',
            'tmp_name' => $tmpFilePath,
            'error' => 0,
            'size' => $fileData['size'] ?? 0
        ];

        $this->log("📤 Enviando archivo a FileUploadService...");

        // ✅ PASO 4: USAR FileUploadService CON LOS PARÁMETROS CORRECTOS
        // Opción A: Usar uploadToConversation (busca/crea chat automáticamente)
        // $result = $fileService->uploadToConversation($uploadedFile, $userId, $realOtherUserId);
        
        // Opción B: Usar uploadFileSimple (cuando ya tenemos el chat_id real)
        $result = $fileService->uploadFileSimple($uploadedFile, $realChatId, $userId);

        // ✅ LIMPIAR ARCHIVO TEMPORAL
        if (file_exists($tmpFilePath)) {
            unlink($tmpFilePath);
            $this->log("🧹 Archivo temporal eliminado: {$tmpFilePath}");
        }

        if (!$result['success']) {
            throw new Exception("Error subiendo archivo: " . ($result['message'] ?? 'Error desconocido'));
        }

      //  $this->log("✅ Archivo subido - Chat: {$result['chat_id'] ?? $realChatId}, File ID: {$result['file_id']}, Message ID: {$result['message_id']}");

        // ✅ PASO 5: OBTENER MENSAJE COMPLETO DESDE BD
        $messageId = $result['message_id'] ?? null;
        $fullMessage = $messageId ? $chatModel->getMessageById($messageId) : null;

        if (!$fullMessage && $messageId) {
            throw new Exception("Mensaje no encontrado después de subir archivo");
        }

        // ✅ PASO 6: PREPARAR RESPUESTA PARA WEB SOCKET
        $response = [
            'type' => $data['type'], // 'image' o 'file'
            'message_id' => $messageId,
            'chat_id' => $realChatId, // ← CHAT_ID REAL
            'user_id' => $userId,
            'other_user_id' => $realOtherUserId,
            'contenido' => $fullMessage['contenido'] ?? $fileData['name'] ?? 'Archivo',
            'tipo' => $fullMessage['tipo'] ?? ($data['type'] === 'image' ? 'imagen' : 'archivo'),
            'timestamp' => $fullMessage['enviado_en'] ?? date('c'),
            'temp_id' => $tempId, // Para correlacionar con el mensaje temporal
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

        // ✅ PASO 7: ENVIAR AL CHAT REAL
        $sent = $this->emitToChat($realChatId, $data['type'], $response);

        if ($sent) {
            $this->log("📤 Archivo propagado a todos en el chat {$realChatId}");
        } else {
            $this->log("⚠️ No hay otros usuarios en el chat {$realChatId}");
        }

        // ✅ PASO 8: ENVIAR AL REMITENTE (siempre)
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
 * ✅ GUARDAR ARCHIVO TEMPORALMENTE DESDE BASE64
 */
private function saveTemporaryFile(array $fileData, $chatId, $userId)
{
    if (!isset($fileData['base64'])) {
        throw new Exception("Datos de archivo incompletos (falta base64)");
    }

    $base64Data = $fileData['base64'];
    
    // Decodificar base64
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

    // Verificar que el archivo se guardó
    if (!file_exists($tmpFilePath)) {
        throw new Exception("Archivo temporal no creado");
    }

    $this->log("💾 Archivo temporal guardado: {$tmpFilePath}");

    return $tmpFilePath;
}

/**
 * ✅ OBTENER EXTENSIÓN DESDE MIME TYPE
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

        $from->userId = $data['user_id'];
        $this->log("Usuario {$from->userId} autenticado en conexión {$from->resourceId}");

        $from->send(json_encode([
            'type' => 'auth_success',
            'user_id' => $from->userId,
            'message' => 'Autenticación exitosa'
        ]));
    }

    // ===================== UNIÓN A CHAT =====================
    private function handleJoinChat(ConnectionInterface $from, array $data)
    {
        if (!isset($data['chat_id'], $data['user_id'])) {
            $this->log("ERROR: Datos de join_chat incompletos");
            return;
        }

        $chatId = $data['chat_id'];

        if (!isset($this->sessions[$chatId])) {
            $this->sessions[$chatId] = new \SplObjectStorage();
            $this->log("Sesión de chat {$chatId} creada");
        }

        if (!$this->sessions[$chatId]->contains($from)) {
            $this->sessions[$chatId]->attach($from);
            $from->currentChat = $chatId;
            $this->log("Cliente {$from->resourceId} agregado al chat {$chatId}");
        }

        $from->send(json_encode([
            'type' => 'joined_chat',
            'chat_id' => $chatId,
            'user_id' => $data['user_id'],
            'message' => 'Unido al chat exitosamente'
        ]));
    }

    // ===================== MENSAJES DE CHAT =====================
    private function handleChatMessage(ConnectionInterface $from, array $data)
    {
                $this->log("Entrando a handlechatmessage");

        try {
            // Verificar datos
            if (!isset($data['chat_id'], $data['user_id'], $data['contenido'])) {
                $this->log("ERROR: Datos incompletos");
                return;
            }

            $receivedIdentifier = $data['chat_id']; // ¡Esto podría ser user_id!
            $userId = $data['user_id'];
            $otherUserId = $data['other_user_id'] ?? null;
                $this->log("Entrando a handlechatmessage2");

            $chatModel = new ChatModel();
                $this->log("Entrando a handlechatmessage3");

            // ✅ **CORRECCIÓN CLAVE:** Determinar el CHAT_ID REAL
            $realChatId = null;

            // CASO A: Si "chat_id" es realmente un chat_id que existe
            if ($chatModel->chatExists($receivedIdentifier)) {
                $realChatId = $receivedIdentifier;
                $this->log("✅ {$receivedIdentifier} es un chat_id válido");
            }
            // CASO B: "chat_id" es en realidad un user_id (el otro usuario)
            else {
                $this->log("🔍 {$receivedIdentifier} no es chat_id. Buscando chat entre usuarios...");

                // Si no tenemos other_user_id, usar el recibido como other_user_id
                if (!$otherUserId) {
                    $otherUserId = $receivedIdentifier;
                }

                // Buscar chat existente entre userId y otherUserId
                $existingChat = $chatModel->findChatBetweenUsers($userId, $otherUserId);

                if ($existingChat) {
                    $realChatId = $existingChat;
                    $this->log("✅ Chat existente encontrado: {$realChatId}");
                } else {
                    // Crear nuevo chat
                    $this->log("🆕 Creando nuevo chat entre {$userId} y {$otherUserId}");
                    $realChatId = $chatModel->createChat([$userId, (int)$otherUserId]);
                    $this->log("✅ Chat creado: {$realChatId}");
                }
            }

            if (!$realChatId) {
                throw new Exception("No se pudo determinar el chat_id real");
            }

            // ✅ Enviar mensaje usando el CHAT_ID REAL
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

            // ✅ Preparar respuesta CON EL CHAT_ID CORRECTO
            $response = [
                'type' => 'chat_message',
                'message_id' => $messageId,
                'chat_id' => $realChatId,  // ← ¡IMPORTANTE! Enviar el chat_id REAL
                'user_id' => $userId,
                'other_user_id' => $otherUserId,
                'contenido' => $data['contenido'],
                'tipo' => $data['tipo'] ?? 'texto',
                'timestamp' => date('c'),
                'temp_id' => $data['temp_id'] ?? null,
                'leido' => 0,
                'user_name' => $fullMessage['user_name'] ?? null
            ];

            $this->log("📤 Enviando al CHAT REAL {$realChatId} (no al 'chat' {$receivedIdentifier})");

            // ✅ Enviar al CHAT REAL
            $this->emitToChat($realChatId, 'chat_message', $response);

            // ✅ También enviar al remitente directamente (por si no está en la sesión)
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

    public function emitToChat($chatId, $eventType, $data, $excludeUserId = null)
    {
        if (!isset($this->sessions[$chatId])) {
            $this->log("⚠️ Chat {$chatId} no encontrado para emitir evento");
            return false;
        }

        // ✅ CORREGIDO: La estructura que espera el frontend
        $messageData = $data; // Ya tiene toda la estructura necesaria
        $messageData['type'] = $eventType; // Asegurar que tenga el type correcto

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
        $line = date('Y-m-d H:i:s') . " | " . $message . "\n";
        file_put_contents(__DIR__ . '/ws.log', $line, FILE_APPEND);
        echo $line;
    }
}

// ===================== INICIO DEL SERVIDOR =====================
$server = \Ratchet\Server\IoServer::factory(
    new \Ratchet\Http\HttpServer(
        new \Ratchet\WebSocket\WsServer(
            new SignalServer()
        )
    ),
    8080
);

echo "Servidor WebSocket iniciado en ws://localhost:8080\n";
$server->run();
