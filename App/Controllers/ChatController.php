<?php

namespace App\Controllers;

use App\Models\ChatModel;
use App\Services\FileUploadService;
use EasyProjects\SimpleRouter\Router;
use Exception;

class ChatController
{
    private ChatModel $chatModel;
    private FileUploadService $fileUploadService;

    public function __construct()
    {
        $this->chatModel = new ChatModel();
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
            $uploadResult = $this->fileUploadService->uploadToConversation($uploadedFile, $user->id, $otherUserId);

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
                "tipo" => $uploadResult['tipo']
            ]);

        } catch (Exception $e) {
            error_log("❌ Error in uploadFile: " . $e->getMessage());
            Router::$response->status(500)->send([
                "success" => false,
                "message" => "Internal server error: " . $e->getMessage()
            ]);
        }
    }

    public function createChat()
    {
        try {
            $body = Router::$request->body;
            $userIds = $body->users ?? null;

            if (!$userIds || !is_array($userIds)) {
                return Router::$response->status(400)->send([
                    "success" => false,
                    "message" => "Missing users"
                ]);
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

            if (!$chatId) {
                $chatId = Router::$request->params->chat_id ?? null;
            }

            if (!$contenido || !$userId) {
                return Router::$response->status(400)->send([
                    "success" => false,
                    "message" => "Missing parameters: contenido y user_id son requeridos"
                ]);
            }

            if ($otherUserId && $otherUserId == $userId) {
                return Router::$response->status(400)->send([
                    "success" => false,
                    "message" => "No puedes enviar mensajes a ti mismo"
                ]);
            }

            if (!$otherUserId) {
                if ($chatId && $chatId != $userId) {
                    $otherUserId = $chatId;
                    $chatId = null;
                } else {
                    return Router::$response->status(400)->send([
                        "success" => false,
                        "message" => "Se necesita other_user_id para identificar con quién chatear"
                    ]);
                }
            }

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
                return Router::$response->status(400)->send([
                    "success" => false,
                    "message" => "Missing chat_id"
                ]);
            }

            $user = Router::$request->user;
            $userId = $user->id ?? null;

            if (!$userId) {
                return Router::$response->status(401)->send([
                    "success" => false,
                    "message" => "Usuario no autenticado"
                ]);
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
                return Router::$response->status(401)->send([
                    "success" => false,
                    "message" => "Usuario no autenticado"
                ]);
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
}