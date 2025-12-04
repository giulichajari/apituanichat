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
        $router->get('/chats', function ($req, $res) use ($tokenMiddleware) {
            $user = $tokenMiddleware->strict(); // ahora devuelve el user
            $userId = $user['id'];

            $controller = new \App\Controllers\ChatController();
            $controller->getChatsByUser($userId);
        });
        $router->get('/users/with-chats', function () use ($tokenMiddleware) {
            $user = $tokenMiddleware->strict();
            $controller = new \App\Controllers\ChatController(); // o ChatController
            $controller->getAllUsersWithChats();
        });
        $router->post(
            '/chats/{chat_id}/upload',
            fn() => $tokenMiddleware->strict(),
            fn() => $chatController->uploadFile()
        );
        // Ver mensajes de un chat
        $router->get(
            '/messages',
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

        // Marcar todo un chat como leído (el que ya tienes)
        $router->patch(
            '/chats/{chat_id}/read',
            fn() => $tokenMiddleware->strict(),
            fn() => $chatController->markChatAsRead()
        );

           $router->post('/chats/upload-file', function ($req, $res) use ($tokenMiddleware) {
            $user = $tokenMiddleware->strict();
            $controller = new \App\Controllers\ChatController();
            $controller->uploadChatFile($user['id']);
        });

        // ✅ ENDPOINT ESPECÍFICO PARA IMÁGENES
        $router->post('/chats/upload-image', function ($req, $res) use ($tokenMiddleware) {
            $user = $tokenMiddleware->strict();
            $controller = new \App\Controllers\ChatController();
            $controller->uploadChatImage($user['id']);
        });

        // ✅ ENDPOINT PARA OBTENER INFO DE ARCHIVO
        $router->get('/files/{file_id}', function ($req, $res) use ($tokenMiddleware) {
            $tokenMiddleware->strict(); // Solo verificar token
            $controller = new \App\Controllers\ChatController();
            $controller->getFileInfo($req->params->file_id);
        });

        // ✅ ENDPOINT PARA DESCARGAR ARCHIVO
        $router->get('/files/{file_id}/download', function ($req, $res) use ($tokenMiddleware) {
            $tokenMiddleware->strict(); // Solo verificar token
            $controller = new \App\Controllers\ChatController();
            $controller->downloadFile($req->params->file_id);
        });
    }
}
