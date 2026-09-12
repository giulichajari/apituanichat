<?php
namespace App\Routers;

use App\Controllers\ReportController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;

class ReportRouter
{
    public function __construct(
        ?Router $router,
        ?TokenMiddleware $tokenMiddleware = new TokenMiddleware(),
        ?ReportController $controller = new ReportController()
    ) {
        $router->post('/reports', fn() => $tokenMiddleware->strict(), fn() => $controller->submit());
        $router->get('/admin/reports', fn() => $tokenMiddleware->strict(), fn() => $controller->listPending());
        $router->post('/admin/reports/{id}/resolve', fn() => $tokenMiddleware->strict(), fn() => $controller->resolve());
        $router->post('/admin/reports/{id}/dismiss', fn() => $tokenMiddleware->strict(), fn() => $controller->dismiss());
    }
}
