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

  public function uploadFile()
{
    try {
        $body = Router::$request->body;
        $user = Router::$request->user ?? null;
        $otherUserId = $body->other_user_id ?? null;

        if (!$user) {
            return Router::$response->status(401)->send([
                "success" => false,
                "message" => "Unauthorized"
            ]);
        }

        if (empty($_FILES) || !isset($_FILES['file'])) {
            return Router::$response->status(400)->send([
                "success" => false,
                "message" => "No file uploaded"
            ]);
        }

        $uploadedFile = $_FILES['file'];
        
        // ✅ USAR EL MÉTODO PRINCIPAL CORREGIDO
        $uploadResult = $this->fileUploadService->uploadToConversation(
            $uploadedFile, 
            $user->id,
            $otherUserId
        );

        if (!$uploadResult['success']) {
            return Router::$response->status(500)->send([
                "success" => false,
                "message" => $uploadResult['message']
            ]);
        }

        Router::$response->status(201)->send([
            "success" => true,
            "message" => "File uploaded successfully",
            "file_id" => $uploadResult['file_id'],
            "file_url" => $uploadResult['file_url'],
            "message_id" => $uploadResult['message_id'],
            "chat_id" => $uploadResult['chat_id'],
            "tipo" => $uploadResult['tipo'],
            "file_name" => $uploadResult['file_name'],
            "file_original_name" => $uploadResult['file_original_name'],
            "file_size" => $uploadResult['file_size'],
            "file_mime_type" => $uploadResult['file_mime_type']
        ]);

    } catch (Exception $e) {
        error_log("❌ Error in uploadFile: " . $e->getMessage());
        Router::$response->status(500)->send([
            "success" => false,
            "message" => "Internal server error: " . $e->getMessage()
        ]);
    }
}

    // Los demás métodos permanecen igual...
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

            // ✅ VALIDACIÓN CRÍTICA: other_user_id no puede ser el mismo que user_id
            if ($otherUserId && $otherUserId == $userId) {
                Router::$response->status(400)->send([
                    "success" => false,
                    "message" => "No puedes enviar mensajes a ti mismo"
                ]);
                return;
            }

            // ✅ Si no tenemos other_user_id, intentar obtenerlo
            if (!$otherUserId) {
                // Si el chatId existe y es diferente al userId, usarlo como other_user_id
                if ($chatId && $chatId != $userId) {
                    $otherUserId = $chatId;
                    $chatId = null; // Forzar búsqueda/creación de chat
                } else {
                    Router::$response->status(400)->send([
                        "success" => false,
                        "message" => "Se necesita other_user_id para identificar con quién chatear"
                    ]);
                    return;
                }
            }

            // ✅ LOG PARA DEBUG
            error_log("📨 Enviando mensaje - User: {$userId}, Other: {$otherUserId}, Chat: {$chatId}");

            // ✅ Usar la función mejorada
            $msgId = $this->chatModel->sendMessage($chatId, $userId, $contenido, $tipo, null, $otherUserId);

            Router::$response->status(201)->send([
                "success" => true,
                "message_id" => $msgId,
                "chat_id" => $chatId,
                "user_id" => $userId,
                "other_user_id" => $otherUserId,
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
