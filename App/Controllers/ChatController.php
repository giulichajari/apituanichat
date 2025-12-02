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
        // DEBUG: Ver qué está llegando
        error_log("📥 BODY recibido: " . print_r(Router::$request->body, true));
        
        $body = Router::$request->body;
        $currentUser = Router::$request->user;
        $currentUserId = $currentUser->id ?? null;
        
        // Obtener other_user_id del body
        $otherUserId = $body->other_user_id ?? null;
        
        // Validaciones
        if (!$currentUserId) {
            Router::$response->status(401)->send([
                "success" => false,
                "message" => "Usuario no autenticado"
            ]);
            return;
        }
        
        if (!$otherUserId) {
            Router::$response->status(400)->send([
                "success" => false,
                "message" => "Falta el parámetro 'other_user_id'"
            ]);
            return;
        }
        
        // Validar que no sea el mismo usuario
        if ($otherUserId == $currentUserId) {
            Router::$response->status(400)->send([
                "success" => false,
                "message" => "No puedes crear un chat contigo mismo"
            ]);
            return;
        }
        
        error_log("🔍 Creando chat entre usuario {$currentUserId} y {$otherUserId}");
        
        // Verificar si ya existe un chat entre estos usuarios
        $chatModel = new ChatModel();
        $existingChatId = $chatModel->findChatBetweenUsers($currentUserId, $otherUserId);
        
        if ($existingChatId) {
            error_log("✅ Chat ya existe: {$existingChatId}");
            Router::$response->status(200)->send([
                "success" => true,
                "chat_id" => $existingChatId,
                "message" => "El chat ya existe",
                "already_exists" => true
            ]);
            return;
        }
        
        // Crear nuevo chat
        $userIds = [$currentUserId, (int)$otherUserId];
        error_log("🆕 Creando nuevo chat con usuarios: " . implode(', ', $userIds));
        
        $chatId = $chatModel->createChat($userIds);
        
        error_log("✅ Chat creado exitosamente: {$chatId}");
        
        Router::$response->status(201)->send([
            "success" => true,
            "chat_id" => $chatId,
            "message" => "Chat creado exitosamente",
            "already_exists" => false
        ]);
        
    } catch (Exception $e) {
        error_log("❌ Error creating chat: " . $e->getMessage());
        Router::$response->status(500)->send([
            "success" => false,
            "message" => "Error creando chat: " . $e->getMessage()
        ]);
    }
}

    public function sendMessage()
    {
        try {
            $user = Router::$request->user;
            $userId = $user->id ?? null;

            $body = Router::$request->body;

            $contenido = trim($body->contenido ?? '');
            $tipo = $body->tipo ?? 'texto';
            $otherUserId = $body->other_user_id ?? null;

            // Validaciones básicas
            if (!$userId || !$contenido) {
                return Router::$response->status(400)->send([
                    "success" => false,
                    "message" => "Faltan parámetros: contenido y user_id son obligatorios"
                ]);
            }

            // No puede enviarse mensaje a sí mismo
            if ($otherUserId == $userId) {
                return Router::$response->status(400)->send([
                    "success" => false,
                    "message" => "No puedes enviarte mensajes a ti mismo"
                ]);
            }

            if (!$otherUserId) {
                return Router::$response->status(400)->send([
                    "success" => false,
                    "message" => "Se necesita other_user_id para identificar con quién chatear"
                ]);
            }

            $msgId = $this->chatModel->sendMessage(
                null, // chatId = null, para que el modelo lo busque/creé
                $userId,
                $contenido,
                $tipo,
                null, // file_id
                $otherUserId // otherUserId
            );

            // Obtener el chat_id que se usó (necesitarás un getter)
            $chatId = $this->chatModel->getLastUsedChatId();

            return Router::$response->status(201)->send([
                "success" => true,
                "message_id" => $msgId,
                "chat_id" => $chatId,
                "user_id" => $userId,
                "other_user_id" => $otherUserId,
                "message" => "Message sent successfully"
            ]);
        } catch (Exception $e) {
            error_log("Error enviando mensaje: " . $e->getMessage());
            return Router::$response->status(500)->send([
                "success" => false,
                "message" => "Error sending message: " . $e->getMessage()
            ]);
        }
    }
public function getMessages()
{
    try {
        // 1. Obtener parámetros - CORREGIDO: Acceder como objeto
        $query = Router::$request->query ?? (object)$_GET;
        
        // Acceder a la propiedad chat_id del objeto
        $chatId = $query->chat_id ?? null;

        // 2. Obtener usuario autenticado
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        // 3. Validaciones básicas
        if (!$chatId) {
            return Router::$response->status(400)->send([
                "success" => false,
                "message" => "El parámetro 'chat_id' es requerido"
            ]);
        }

        // Asegurar que sea numérico
        if (!is_numeric($chatId)) {
            return Router::$response->status(400)->send([
                "success" => false,
                "message" => "El 'chat_id' debe ser numérico"
            ]);
        }

        $chatId = (int)$chatId;
        $userId = (int)$userId;

        // 4. Instanciar modelo
        $chatModel = new ChatModel();

        // 5. Verificar si el chat existe - IMPORTANTE añadir esta validación
        $chatExists = $chatModel->chatExists($chatId);
        
        if (!$chatExists) {
            // El chat no existe, retornar error
            return Router::$response->status(404)->send([
                "success" => false,
                "message" => "Chat no encontrado",
                "chat_id" => $chatId
            ]);
        }

        // 6. Verificar que el usuario tiene acceso al chat
        $userInChat = $chatModel->userInChat($chatId, $userId);
        
        if (!$userInChat) {
            return Router::$response->status(403)->send([
                "success" => false,
                "message" => "No tienes acceso a este chat"
            ]);
        }

        // 7. Obtener mensajes del chat
        $messages = $chatModel->getMessages($chatId, $userId);

        // 8. Marcar mensajes como leídos (solo si hay mensajes)
        if (count($messages) > 0) {
            $chatModel->markMessagesAsRead($chatId, $userId);
        }

        // 9. Obtener info del otro usuario
        $otherUserId = $chatModel->getOtherUserFromChat($chatId, $userId);

        // 10. Retornar respuesta - ELIMINAR $isNewChat ya que el chat siempre existe aquí
        return Router::$response->status(200)->send([
            "success" => true,
            "data" => $messages,
            "chat_id" => $chatId,
            "other_user_id" => $otherUserId,
            "total_messages" => count($messages),
            "message" => count($messages) > 0
                ? "Mensajes obtenidos exitosamente"
                : "No hay mensajes en este chat"
        ]);
        
    } catch (Exception $e) {
        error_log("❌ Error en ChatController::getMessages: " . $e->getMessage());

        return Router::$response->status(500)->send([
            "success" => false,
            "message" => "Error interno del servidor",
            "error" => $e->getMessage()
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

            $chats = $this->chatModel->getUsersWithChats($userId);

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

    public function getAllUsersWithChats()
    {
        try {
            $user = Router::$request->user;
            $currentUserId = $user->id ?? null;

            if (!$currentUserId) {
                Router::$response->status(401)->send([
                    "success" => false,
                    "message" => "Usuario no autenticado"
                ]);
                return;
            }

            $usersWithChats = $this->chatModel->getUsersWithChats($currentUserId);

            Router::$response->status(200)->send([
                "success" => true,
                "data" => $usersWithChats,
                "message" => "Users with chats retrieved successfully"
            ]);
        } catch (Exception $e) {
            error_log("Error: " . $e->getMessage());
            Router::$response->status(500)->send([
                "success" => false,
                "message" => "Error retrieving users with chats"
            ]);
        }
    }

    // ✅ Marcar un mensaje individual como leído
    public function markMessageAsRead()
    {
        try {
            var_dump(Router::$request->body);
            $messageId = Router::$request->body->message_id ?? null;
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

    public function markChatAsRead()
    {
        try {
            $data = Router::$request->body;
            $otherUserId = $data->user_id ?? null;
            $chatId = $data->chat_id ?? null;
            $currentUser = Router::$request->user ?? null;

            if (!$otherUserId) {
                Router::$response->status(400)->send([
                    "success" => false,
                    "message" => "ID del otro usuario requerido"
                ]);
                return;
            }

            if (!$currentUser) {
                Router::$response->status(401)->send([
                    "success" => false,
                    "message" => "Usuario no autenticado"
                ]);
                return;
            }

            // Obtener el chat_id real basado en los dos usuarios
            $chatId = $this->chatModel->getChatIdByUsers($currentUser->id, $chatId);

            if (!$chatId) {
                Router::$response->status(404)->send([
                    "success" => false,
                    "message" => "Chat no encontrado"
                ]);
                return;
            }

            // Marcar mensajes como leídos usando el modelo
            $affectedRows = $this->chatModel->markMessagesAsRead($chatId, $currentUser->id);

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
