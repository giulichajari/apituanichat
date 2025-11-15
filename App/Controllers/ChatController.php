<?php

namespace App\Controllers;

use App\Models\ChatModel;
use App\Models\File;
use App\Services\FileUploadService;
use EasyProjects\SimpleRouter\Router;

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
        $body = Router::$request->body;
        $chatId = $body->chat_id ?? null;
        $user = Router::$request->user ?? null;

        error_log("🔍 Body chat_id: " . ($chatId ?? 'NULL'));
        error_log("🔍 Full body: " . print_r($body, true));
        error_log("🔍 User: " . print_r($user, true));
        error_log("🔍 Files: " . print_r($_FILES, true));

        if (!$chatId) {
            Router::$response->status(400)->send(["message" => "Missing chat_id"]);
            return;
        }

        if (!$user) {
            Router::$response->status(401)->send(["message" => "Unauthorized"]);
            return;
        }

        // Verificar si se envió un archivo
        if (empty($_FILES) || !isset($_FILES['file'])) {
            Router::$response->status(400)->send(["message" => "No file uploaded"]);
            return;
        }

        $uploadedFile = $_FILES['file'];

        try {
            // Validar el archivo
            $validation = $this->fileUploadService->validateFile($uploadedFile);
            if (!$validation['success']) {
                Router::$response->status(400)->send(["message" => $validation['message']]);
                return;
            }

            // Subir el archivo
            $uploadResult = $this->fileUploadService->upload($uploadedFile, $chatId, $user->id);

            if (!$uploadResult['success']) {
                Router::$response->status(500)->send(["message" => $uploadResult['message']]);
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
                Router::$response->status(500)->send(["message" => "Error saving file to database"]);
            }
        } catch (\Exception $e) {
            error_log("❌ Error in uploadFile: " . $e->getMessage());
            Router::$response->status(500)->send(["message" => "Internal server error"]);
        }
    }
    // ✅ Crear un chat nuevo (1 a 1 o grupal)
    public function createChat()
    {
        $body = Router::$request->body;
        $userIds = $body->users ?? null;

        if (!$userIds || !is_array($userIds)) {
            Router::$response->status(400)->send(["message" => "Missing users"]);
            return;
        }

        $chatId = $this->chatModel->createChat($userIds);

        if ($chatId) {
            Router::$response->status(201)->send([
                "chat_id" => $chatId,
                "message" => "Chat created"
            ]);
        } else {
            Router::$response->status(500)->send(["message" => "Error creating chat"]);
        }
    }

    // 📨 Enviar mensaje en un chat
    public function sendMessage()
    {
        // ✅ Usuario desde Request (consistente)
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        // ✅ Body del request
        $body = Router::$request->body;
        $chatId = $body->chat_id ?? null;
        $contenido = trim($body->contenido ?? '');
        $tipo = $body->tipo ?? 'texto';

        if (!$chatId || !$contenido || !$userId) {
            Router::$response->status(400)->send([
                "message" => "Missing parameters"
            ]);
            return;
        }

        $msgId = $this->chatModel->sendMessage($chatId, $userId, $contenido, $tipo);

        Router::$response->status(201)->send([
            "message_id" => $msgId,
            "chat_id" => $chatId,
            "message" => "Message sent"
        ]);
    }

    // 📄 Obtener mensajes de un chat - CORREGIDO
    public function getMessages()
    {
        // ✅ Usar Router::$request de manera consistente
        $query = Router::$request->query;
        $chatId = $query->chat_id ?? null;

        if (!$chatId) {
            Router::$response->status(400)->send(["message" => "Missing chat_id"]);
            return;
        }

        // ✅ Obtener userId del usuario autenticado
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        if (!$userId) {
            Router::$response->status(401)->send(["message" => "Usuario no autenticado"]);
            return;
        }

        $messages = $this->chatModel->getMessages($chatId, $userId);

        // ✅ DEBUG: Verificar qué campos están llegando
        error_log("📨 Mensajes enviados al frontend: " . count($messages));
        foreach ($messages as $index => $msg) {
            if (
                strpos($msg['contenido'], '.jpg') !== false ||
                strpos($msg['contenido'], '.png') !== false ||
                strpos($msg['contenido'], '.webp') !== false
            ) {
                error_log("🔍 Mensaje {$index} (ID: {$msg['id']}):");
                error_log("   - contenido: {$msg['contenido']}");
                error_log("   - file_name: " . ($msg['file_name'] ?? 'NULL'));
                error_log("   - file_url: " . ($msg['file_url'] ?? 'NULL'));
                error_log("   - file_original_name: " . ($msg['file_original_name'] ?? 'NULL'));
            }
        }

        Router::$response->status(200)->send([
            "data" => $messages,
            "chat_id" => $chatId,
            "message" => "Messages retrieved"
        ]);
    }

    // 📬 Listar chats de un usuario - CORREGIDO
    public function getChatsByUser()
    {
        // ✅ Obtener userId del usuario autenticado
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        if (!$userId) {
            Router::$response->status(401)->send(["message" => "Usuario no autenticado"]);
            return;
        }

        $chats = $this->chatModel->getChatsByUser($userId);

        Router::$response->status(200)->send([
            "data" => $chats,
            "message" => "Chats retrieved"
        ]);
    }

    // ✅ Marcar chat como leído - CORREGIDO
    public function markAsRead()
    {
        // ✅ Usar Router::$request de manera consistente
        $body = Router::$request->body;
        $user = Router::$request->user;

        $chatId = $body->chat_id ?? null;
        $userId = $user->id ?? null;

        if (!$chatId || !$userId) {
            Router::$response->status(400)->send(["message" => "Missing parameters"]);
            return;
        }

        $result = $this->chatModel->markAsRead($chatId, $userId);

        Router::$response->status(200)->send([
            "chat_id" => $chatId,
            "message" => "Chat marked as read"
        ]);
    }
}
