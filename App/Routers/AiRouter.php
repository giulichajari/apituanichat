<?php

namespace App\Routers;

use App\Controllers\AiController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;

class AiRouter
{
    public function __construct(
        ?Router $router,
        ?TokenMiddleware $tokenMiddleware = new TokenMiddleware(),
        ?AiController $aiController = new AiController()
    ) {
        $router->post(
            '/ai/chat',
            fn() => $tokenMiddleware->strict(),
            fn() => $aiController->chat()
        );
    }
}
