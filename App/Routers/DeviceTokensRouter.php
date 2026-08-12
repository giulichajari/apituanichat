<?php

namespace App\Routers;

use App\Controllers\DeviceTokenController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;

class DeviceTokensRouter
{
    public function __construct(
        ?Router $router,
        ?TokenMiddleware $tokenMiddleware = new TokenMiddleware(),
        ?DeviceTokenController $deviceTokenController = new DeviceTokenController()
    ) {
        $router->post(
            '/device-tokens',
            fn() => $tokenMiddleware->strict(),
            fn() => $deviceTokenController->register()
        );

        $router->post(
            '/device-tokens/unregister',
            fn() => $tokenMiddleware->strict(),
            fn() => $deviceTokenController->unregister()
        );
    }
}
