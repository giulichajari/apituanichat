<?php

namespace App\Routers;

use App\Controllers\ChatController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;

class ChatRouter
{
    public function __construct(
        ?Router $router,
        ?TokenMiddleware $tokenMiddleware = new TokenMiddleware(),
        ?ChatController $chatController = new ChatController(),
    ) {

        // Listar todos los chats del usuario logueado
       $router->get('/chats', function($req, $res){
    $controller = new \App\Controllers\ChatController();
    $userId = Router::$request->query->user_id ?? null; // Obtener userId desde query
    if (!$userId) {
        Router::$response->status(400)->send(["message" => "Missing user_id"]);
        return;
    }
    $controller->getChatsByUser($userId); // ✅ Método correcto
});


        // Ver mensajes de un chat
        $router->get(
            '/chats/{chat_id}/messages',
            fn() => $tokenMiddleware->strict(),
            fn() => $chatController->getMessages()
        );

        // Enviar un mensaje en un chat
        $router->post(
            '/chats/{chat_id}/messages',
            fn() => $tokenMiddleware->strict(),
            fn() => $chatController->sendMessage()
        );

        // Crear un nuevo chat (por ejemplo, al iniciar conversación)
        $router->post(
            '/chats',
            fn() => $tokenMiddleware->strict(),
            fn() => $chatController->createChat()
        );
    }
}
