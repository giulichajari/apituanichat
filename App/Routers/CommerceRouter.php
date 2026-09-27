<?php
namespace App\Routers;

use App\Controllers\CommerceController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;

final class CommerceRouter
{
    public function __construct(Router $router)
    {
        $auth = new TokenMiddleware();
        $controller = new CommerceController();
        $router->get('/commerce/orders', fn() => $auth->strict(), fn() => $controller->history());
        $router->post('/commerce/orders/action', fn() => $auth->strict(), fn() => $controller->transition());
    }
}
