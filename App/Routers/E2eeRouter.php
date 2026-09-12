<?php
namespace App\Routers;
use App\Controllers\E2eeController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;
class E2eeRouter
{
    public function __construct(
        ?Router $router,
        ?TokenMiddleware $tokenMiddleware = null,
        ?E2eeController $controller = null
    ) {
        $tokenMiddleware = $tokenMiddleware ?? new TokenMiddleware();
        $controller = $controller ?? new E2eeController();

        $router->post(
            '/e2ee/bundle',
            fn() => $tokenMiddleware->strict(),
            fn() => $controller->uploadBundle()
        );
        $router->post(
            '/e2ee/prekeys',
            fn() => $tokenMiddleware->strict(),
            fn() => $controller->replenishPrekeys()
        );
        $router->get(
            '/e2ee/prekeys/count',
            fn() => $tokenMiddleware->strict(),
            fn() => $controller->getMyPrekeyCount()
        );
        $router->get(
            '/e2ee/bundle/{idUser}',
            fn() => $tokenMiddleware->strict(),
            fn() => $controller->getBundle()
        );
        $router->post(
            '/e2ee/chats/{idChat}/enable',
            fn() => $tokenMiddleware->strict(),
            fn() => $controller->enableForChat()
        );
        $router->get(
            '/e2ee/chats/{idChat}/status',
            fn() => $tokenMiddleware->strict(),
            fn() => $controller->getChatStatus()
        );
    }
}
