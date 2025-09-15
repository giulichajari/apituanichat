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
   $router->get('/chats', function($req, $res) use ($tokenMiddleware) {
$user = $tokenMiddleware->strict(); // ahora devuelve el user
    $userId = $user['id'];

    $controller = new \App\Controllers\ChatController();
    $controller->getChatsByUser($userId);
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
