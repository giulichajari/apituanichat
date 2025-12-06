<?php

namespace App\Routers;

use App\Controllers\StatusController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;

class StatusRouter
{
    public function __construct(
        ?Router $router,
        ?TokenMiddleware $tokenMiddleware = new TokenMiddleware(),
        ?StatusController $statusController = new StatusController()
    ) {
        // Subir un nuevo estado (imagen/video)
        $router->post(
            '/status',
            fn() => $tokenMiddleware->strict(),
            fn() => $statusController->uploadStatus()
        );

        // Obtener todos los estados activos (de todos los usuarios)
        $router->get(
            '/statuses',
            fn() => $tokenMiddleware->strict(),
            fn() => $statusController->getAllStatuses()
        );

        // Obtener mis estados activos
        $router->get(
            '/me/statuses',
            fn() => $tokenMiddleware->strict(),
            fn() => $statusController->getMyStatuses()
        );

        // Ver un estado específico
        $router->get(
            '/status/{id}',
            fn() => $tokenMiddleware->strict(),
            fn() => $statusController->getStatus()
        );

        // Marcar estado como visto
        $router->post(
            '/status/{id}/view',
            fn() => $tokenMiddleware->strict(),
            fn() => $statusController->markAsViewed()
        );

        // Eliminar un estado
        $router->delete(
            '/status/{id}',
            fn() => $tokenMiddleware->strict(),
            fn() => $statusController->deleteStatus()
        );

        // Obtener estados expirados (para limpieza)
        $router->get(
            '/statuses/expired',
            fn() => $tokenMiddleware->strict(),
            fn() => $statusController->getExpiredStatuses()
        );

        // Obtener estados de un usuario específico
        $router->get(
            '/user/{userId}/statuses',
            fn() => $tokenMiddleware->strict(),
            fn() => $statusController->getUserStatuses()
        );

        // Obtener contadores de vistas
        $router->get(
            '/status/{id}/views',
            fn() => $tokenMiddleware->strict(),
            fn() => $statusController->getStatusViews()
        );
    }
}