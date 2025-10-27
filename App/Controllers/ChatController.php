<?php

namespace App\Controllers;

use App\Models\ChatModel;
use EasyProjects\SimpleRouter\Router;

class ChatController
{
    public function __construct(
        private ?ChatModel $chatModel = new ChatModel(),
    ) {}

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

        // Traer mensajes desde el modelo
        $messages = $this->chatModel->getMessages($chatId, $userId);

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
