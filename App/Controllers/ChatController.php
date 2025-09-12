<?php
namespace App\Controllers;

use App\Models\ChatModel;
use EasyProjects\SimpleRouter\Router;

class ChatController {
    public function __construct(
        private ?ChatModel $chatModel = new ChatModel(),
    ){}

    // Crear un chat nuevo (1 a 1 o grupal)
    public function createChat(){
        $userIds = Router::$request->body->users ?? null; // [1,2] para chat entre 2
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

    // Obtener todos los chats de un usuario
    public function getChatsByUser($userId){
        $chats = $this->chatModel->getChatsByUser($userId);

        Router::$response->status(200)->send([
            "data" => $chats,
            "message" => "Chats retrieved"
        ]);
    }

    // Enviar mensaje en un chat
    public function sendMessage(){
        $chatId = Router::$request->body->chat_id ?? null;
        $userId = Router::$request->body->user_id ?? null;
        $content = Router::$request->body->contenido ?? null;
        $tipo = Router::$request->body->tipo ?? 'texto';

        if (!$chatId || !$userId || !$content) {
            Router::$response->status(400)->send(["message" => "Missing parameters"]);
            return;
        }

        $msgId = $this->chatModel->sendMessage($chatId, $userId, $content, $tipo);

        if ($msgId) {
            Router::$response->status(201)->send([
                "message_id" => $msgId,
                "message" => "Message sent"
            ]);
        } else {
            Router::$response->status(500)->send(["message" => "Error sending message"]);
        }
    }

    // Obtener mensajes de un chat
    public function getMessages($chatId){
        $messages = $this->chatModel->getMessages($chatId);

        Router::$response->status(200)->send([
            "data" => $messages,
            "message" => "Messages retrieved"
        ]);
    }
}
