<?php

namespace App\Controllers;

use App\Models\ChatModel;
use App\Models\File;
use App\Services\FileUploadService;
use EasyProjects\SimpleRouter\Router;
use Exception;

class ChatController
{
    private FileUploadService $fileUploadService;

    public function __construct(
        private ?ChatModel $chatModel = new ChatModel()
    ) {
        $this->fileUploadService = new FileUploadService();
    }

    // ✅ Subir archivo o imagen a un chat
    public function uploadFile()
    {
        try {
            $body = Router::$request->body;
            $chatId = $body->chat_id ?? null;
            $user = Router::$request->user ?? null;

            error_log("🔍 Body chat_id: " . ($chatId ?? 'NULL'));
            error_log("🔍 User ID: " . ($user->id ?? 'NULL'));

            if (!$chatId) {
                Router::$response->status(400)->send([
                    "success" => false,
                    "message" => "Missing chat_id"
                ]);
                return;
            }

            if (!$user) {
                Router::$response->status(401)->send([
                    "success" => false,
                    "message" => "Unauthorized"
                ]);
                return;
            }

            // Verificar si se envió un archivo
            if (empty($_FILES) || !isset($_FILES['file'])) {
                Router::$response->status(400)->send([
                    "success" => false,
                    "message" => "No file uploaded"
                ]);
                return;
            }

            $uploadedFile = $_FILES['file'];

            // Validar el archivo
            $validation = $this->fileUploadService->validateFile($uploadedFile);
            if (!$validation['success']) {
                Router::$response->status(400)->send([
                    "success" => false,
                    "message" => $validation['message']
                ]);
                return;
            }

            // Subir el archivo
            $uploadResult = $this->fileUploadService->upload($uploadedFile, $chatId, $user->id);

            if (!$uploadResult['success']) {
                Router::$response->status(500)->send([
                    "success" => false,
                    "message" => $uploadResult['message']
                ]);
                return;
            }

            // Crear registro en la base de datos
            $fileData = [
                'name' => $uploadResult['file_name'],
                'original_name' => $uploadedFile['name'],
                'path' => $uploadResult['file_path'],
                'url' => $uploadResult['file_url'],
                'size' => $uploadedFile['size'],
                'mime_type' => $uploadedFile['type'],
                'chat_id' => $chatId,
                'user_id' => $user->id,
                'message_id' => $uploadResult['message_id']
            ];

            $fileModel = new File();
            $fileId = $fileModel->create($fileData);

            if ($fileId) {
                Router::$response->status(201)->send([
                    "success" => true,
                    "message" => "File uploaded successfully",
                    "file_id" => $fileId,
                    "file_url" => $uploadResult['file_url'],
                    "message_id" => $uploadResult['message_id'],
                    "file_data" => $fileData
                ]);
            } else {
                Router::$response->status(500)->send([
                    "success" => false,
                    "message" => "Error saving file to database"
                ]);
            }
        } catch (Exception $e) {
            error_log("❌ Error in uploadFile: " . $e->getMessage());
            Router::$response->status(500)->send([
                "success" => false,
                "message" => "Internal server error"
            ]);
        }
    }

    // ✅ Crear un chat nuevo (1 a 1 o grupal)
    public function createChat()
    {
        try {
            $body = Router::$request->body;
            $userIds = $body->users ?? null;

            if (!$userIds || !is_array($userIds)) {
                Router::$response->status(400)->send([
                    "success" => false,
                    "message" => "Missing users"
                ]);
                return;
            }

            $chatId = $this->chatModel->createChat($userIds);

            Router::$response->status(201)->send([
                "success" => true,
                "chat_id" => $chatId,
                "message" => "Chat created successfully"
            ]);
        } catch (Exception $e) {
            error_log("Error creating chat: " . $e->getMessage());
            Router::$response->status(500)->send([
                "success" => false,
                "message" => "Error creating chat: " . $e->getMessage()
            ]);
        }
    }

   // En ChatController.php - método sendMessage mejorado
public function sendMessage()
{
    try {
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        $body = Router::$request->body;
        $chatId = $body->chat_id ?? null;
        $contenido = trim($body->contenido ?? '');
        $tipo = $body->tipo ?? 'texto';
        $otherUserId = $body->other_user_id ?? null;

        // ✅ Obtener chat_id de los parámetros de la ruta si no viene en el body
        if (!$chatId) {
            $chatId = Router::$request->params->chat_id ?? null;
        }

        if (!$contenido || !$userId) {
            Router::$response->status(400)->send([
                "success" => false,
                "message" => "Missing parameters: contenido y user_id son requeridos"
            ]);
            return;
        }

        // ✅ Si el chat podría no existir, necesitamos other_user_id
        if (!$otherUserId) {
            // Intentar obtener el otro usuario del chat existente
            $otherUserId = $this->getOtherUserFromChat($chatId, $userId);
            
            if (!$otherUserId) {
                Router::$response->status(400)->send([
                    "success" => false,
                    "message" => "Se necesita other_user_id para crear un chat nuevo o el chat no existe"
                ]);
                return;
            }
        }

        // ✅ Usar la función mejorada que crea chat si no existe
        $msgId = $this->chatModel->sendMessage($chatId, $userId, $contenido, $tipo, null, $otherUserId);

        Router::$response->status(201)->send([
            "success" => true,
            "message_id" => $msgId,
            "chat_id" => $chatId,
            "message" => "Message sent successfully"
        ]);

    } catch (Exception $e) {
        error_log("Error enviando mensaje: " . $e->getMessage());
        Router::$response->status(500)->send([
            "success" => false,
            "message" => "Error sending message: " . $e->getMessage()
        ]);
    }
}

// ✅ Método auxiliar para obtener el otro usuario de un chat existente
private function getOtherUserFromChat($chatId, $currentUserId)
{
    try {
        if (!$chatId) return null;
        
        $stmt = $this->chatModel->db->prepare("
            SELECT user_id FROM chat_usuarios 
            WHERE chat_id = ? AND user_id != ?
            LIMIT 1
        ");
        $stmt->execute([$chatId, $currentUserId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? $result['user_id'] : null;
    } catch (Exception $e) {
        error_log("Error obteniendo otro usuario del chat: " . $e->getMessage());
        return null;
    }
}

    // ✅ Obtener mensajes de un chat
    public function getMessages()
    {
        try {
            $query = Router::$request->query;
            $chatId = $query->chat_id ?? null;

            if (!$chatId) {
                Router::$response->status(400)->send([
                    "success" => false,
                    "message" => "Missing chat_id"
                ]);
                return;
            }

            $user = Router::$request->user;
            $userId = $user->id ?? null;

            if (!$userId) {
                Router::$response->status(401)->send([
                    "success" => false,
                    "message" => "Usuario no autenticado"
                ]);
                return;
            }

            $messages = $this->chatModel->getMessages($chatId, $userId);

            // Log para debugging
            error_log("📨 Mensajes enviados al frontend: " . count($messages));
            foreach ($messages as $index => $msg) {
                if (isset($msg['file_name']) && $msg['file_name']) {
                    error_log("🔍 Mensaje {$index} (ID: {$msg['id']}):");
                    error_log("   - contenido: {$msg['contenido']}");
                    error_log("   - file_name: " . ($msg['file_name'] ?? 'NULL'));
                    error_log("   - file_url: " . ($msg['file_url'] ?? 'NULL'));
                }
            }

            Router::$response->status(200)->send([
                "success" => true,
                "data" => $messages,
                "chat_id" => $chatId,
                "message" => "Messages retrieved successfully"
            ]);
        } catch (Exception $e) {
            error_log("Error obteniendo mensajes: " . $e->getMessage());
            Router::$response->status(500)->send([
                "success" => false,
                "message" => "Error retrieving messages: " . $e->getMessage()
            ]);
        }
    }

    // ✅ Listar chats de un usuario
    public function getChatsByUser()
    {
        try {
            $user = Router::$request->user;
            $userId = $user->id ?? null;

            if (!$userId) {
                Router::$response->status(401)->send([
                    "success" => false,
                    "message" => "Usuario no autenticado"
                ]);
                return;
            }

            $chats = $this->chatModel->getChatsByUser($userId);

            Router::$response->status(200)->send([
                "success" => true,
                "data" => $chats,
                "message" => "Chats retrieved successfully"
            ]);
        } catch (Exception $e) {
            error_log("Error obteniendo chats: " . $e->getMessage());
            Router::$response->status(500)->send([
                "success" => false,
                "message" => "Error retrieving chats: " . $e->getMessage()
            ]);
        }
    }

    // ✅ Marcar un mensaje individual como leído
    public function markMessageAsRead()
    {
        try {
            $messageId = Router::$request->params->message_id ?? null;
            $user = Router::$request->user ?? null;

            if (!$messageId) {
                Router::$response->status(400)->send([
                    "success" => false,
                    "message" => "ID de mensaje requerido"
                ]);
                return;
            }

            if (!$user) {
                Router::$response->status(401)->send([
                    "success" => false,
                    "message" => "Usuario no autenticado"
                ]);
                return;
            }

            // Verificar que el mensaje existe y pertenece a un chat del usuario
            $message = $this->getMessageById($messageId);
            if (!$message) {
                Router::$response->status(404)->send([
                    "success" => false,
                    "message" => "Mensaje no encontrado"
                ]);
                return;
            }

            // Verificar que el usuario tiene acceso a este chat
            $chatAccess = $this->verifyChatAccess($message['chat_id'], $user->id);
            if (!$chatAccess) {
                Router::$response->status(403)->send([
                    "success" => false,
                    "message" => "No tienes acceso a este chat"
                ]);
                return;
            }

            // Actualizar el campo leido
            $stmt = $this->chatModel->db->prepare("
                UPDATE mensajes 
                SET leido = 1, 
                    fecha_leido = NOW() 
                WHERE id = ? AND leido = 0
            ");

            $stmt->execute([$messageId]);
            $affectedRows = $stmt->rowCount();

            if ($affectedRows > 0) {
                // Emitir evento WebSocket de que el mensaje fue leído
                $this->emitMessageReadEvent($messageId, $user->id);

                Router::$response->status(200)->send([
                    "success" => true,
                    "message" => "Mensaje marcado como leído",
                    "data" => [
                        "message_id" => $messageId,
                        "leido" => 1,
                        "fecha_leido" => date('Y-m-d H:i:s')
                    ]
                ]);
            } else {
                Router::$response->status(400)->send([
                    "success" => false,
                    "message" => "El mensaje ya estaba marcado como leído o no existe"
                ]);
            }
        } catch (Exception $e) {
            error_log("Error en markMessageAsRead: " . $e->getMessage());
            Router::$response->status(500)->send([
                "success" => false,
                "message" => "Error interno del servidor"
            ]);
        }
    }

    // ✅ Marcar todo un chat como leído
    public function markChatAsRead()
    {
        try {
            $chatId = Router::$request->params->chat_id ?? null;
            $user = Router::$request->user ?? null;

            if (!$chatId) {
                Router::$response->status(400)->send([
                    "success" => false,
                    "message" => "ID de chat requerido"
                ]);
                return;
            }

            if (!$user) {
                Router::$response->status(401)->send([
                    "success" => false,
                    "message" => "Usuario no autenticado"
                ]);
                return;
            }

            // Verificar acceso al chat
            $chatAccess = $this->verifyChatAccess($chatId, $user->id);
            if (!$chatAccess) {
                Router::$response->status(403)->send([
                    "success" => false,
                    "message" => "No tienes acceso a este chat"
                ]);
                return;
            }

            // Marcar todos los mensajes no leídos del chat como leídos
            $stmt = $this->chatModel->db->prepare("
                UPDATE mensajes 
                SET leido = 1, 
                    fecha_leido = NOW() 
                WHERE chat_id = ? 
                AND user_id != ? 
                AND leido = 0
            ");

            $stmt->execute([$chatId, $user->id]);
            $affectedRows = $stmt->rowCount();

            Router::$response->status(200)->send([
                "success" => true,
                "message" => "Chat marcado como leído",
                "data" => [
                    "chat_id" => $chatId,
                    "mensajes_actualizados" => $affectedRows
                ]
            ]);
        } catch (Exception $e) {
            error_log("Error en markChatAsRead: " . $e->getMessage());
            Router::$response->status(500)->send([
                "success" => false,
                "message" => "Error interno del servidor"
            ]);
        }
    }

    // ==================== MÉTODOS PRIVADOS AUXILIARES ====================

    /**
     * Método auxiliar para obtener mensaje por ID
     */
    private function getMessageById($messageId)
    {
        try {
            $stmt = $this->chatModel->db->prepare("
                SELECT m.*, c.id as chat_id 
                FROM mensajes m 
                JOIN chats c ON m.chat_id = c.id 
                WHERE m.id = ?
            ");
            $stmt->execute([$messageId]);
            return $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error obteniendo mensaje por ID: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Verificar acceso al chat
     */
    private function verifyChatAccess($chatId, $userId)
    {
        try {
            // NOTA: Tu tabla se llama chat_usuarios, no chat_users
            $stmt = $this->chatModel->db->prepare("
                SELECT 1 FROM chat_usuarios 
                WHERE chat_id = ? AND user_id = ?
            ");
            $stmt->execute([$chatId, $userId]);
            return $stmt->fetch() !== false;
        } catch (Exception $e) {
            error_log("Error verificando acceso al chat: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Emitir evento WebSocket
     */
    private function emitMessageReadEvent($messageId, $userId)
    {
        try {
            // Obtener información del mensaje
            $message = $this->getMessageById($messageId);
            if (!$message) {
                error_log("❌ No se pudo obtener mensaje para emitir evento de lectura");
                return;
            }

            // Datos para enviar al WebSocket
            $wsData = [
                'type' => 'message_read',
                'message_id' => $messageId,
                'user_id' => $userId,
                'chat_id' => $message['chat_id'],
                'timestamp' => date('c')
            ];

            // Enviar al servidor WebSocket
            $this->sendToWebSocket($wsData);

            error_log("✅ Evento message_read enviado al WebSocket para mensaje: " . $messageId);
        } catch (Exception $e) {
            error_log("❌ Error enviando evento message_read al WebSocket: " . $e->getMessage());
            // No romper el flujo principal si falla el WebSocket
        }
    }

    private function sendToWebSocket($data)
    {
        try {
            // Conectar al WebSocket y enviar el mensaje
            $client = new \WebSocket\Client("ws://localhost:8080");
            $client->text(json_encode($data));
            $client->close();
        } catch (Exception $e) {
            error_log("❌ Error conectando al WebSocket: " . $e->getMessage());
        }
    }
}
