<?php
namespace App\Routers;

use App\Controllers\AgeVerificationController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;

class AgeVerificationRouter
{
    public function __construct(
        ?Router $router,
        ?TokenMiddleware $tokenMiddleware = new TokenMiddleware(),
        ?AgeVerificationController $controller = new AgeVerificationController()
    ) {
        $router->post(
            '/age-verification/submit',
            fn() => $tokenMiddleware->strict(),
            fn() => $controller->submit()
        );
        $router->get(
            '/age-verification/status',
            fn() => $tokenMiddleware->strict(),
            fn() => $controller->getMyStatus()
        );
        $router->get(
            '/admin/age-verifications',
            fn() => $tokenMiddleware->strict(),
            fn() => $controller->listPending()
        );
        $router->post(
            '/admin/age-verifications/{id}/approve',
            fn() => $tokenMiddleware->strict(),
            fn() => $controller->approve()
        );
        $router->post(
            '/admin/age-verifications/{id}/reject',
            fn() => $tokenMiddleware->strict(),
            fn() => $controller->reject()
        );
    }
}
