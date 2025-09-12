<?php

namespace App\Routers;

use App\Controllers\ProfileController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;

class ProfileRouter
{
    public function __construct(
        ?Router $router,
        ?TokenMiddleware $tokenMiddleware = new TokenMiddleware(),
        ?ProfileController $profileController = new ProfileController()
    ) {
        // Obtener perfil por userId
        $router->get(
            '/profile/{userId}',
            fn() => $tokenMiddleware->strict(),
            fn() => $profileController->getProfile()
        );

        // Crear perfil
        $router->post(
            '/profile',
            fn() => $tokenMiddleware->strict(),
            fn() => $profileController->createProfile()
        );

        // Actualizar perfil completo
        $router->put(
            '/profile/{userId}',
            fn() => $tokenMiddleware->strict(),
            fn() => $profileController->updateProfile()
        );

        // Actualizar solo avatar
        $router->post(
            '/profile/avatar/{userId}',
            fn() => $tokenMiddleware->strict(),
            fn() => $profileController->updateAvatar()
        );
    }
}
