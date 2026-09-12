<?php

namespace App\Routers;

use App\Controllers\ScheduledPaymentController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;

class ScheduledPaymentsRouter
{
    public function __construct(
        ?Router $router,
        ?TokenMiddleware $tokenMiddleware = null,
        ?ScheduledPaymentController $controller = null
    ) {
        $tokenMiddleware = $tokenMiddleware ?? new TokenMiddleware();
        $controller = $controller ?? new ScheduledPaymentController();

        $router->post(
            '/wallet/scheduled-payments',
            fn() => $tokenMiddleware->strict(),
            fn() => $controller->create()
        );

        $router->get(
            '/wallet/scheduled-payments',
            fn() => $tokenMiddleware->strict(),
            fn() => $controller->list()
        );

        $router->delete(
            '/wallet/scheduled-payments/{idPago}',
            fn() => $tokenMiddleware->strict(),
            fn() => $controller->cancel()
        );
    }
}
