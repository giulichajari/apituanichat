<?php
namespace App\Routers;

use App\Controllers\FamilyController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;

class FamilyRouter
{
    public function __construct(
        ?Router $router,
        ?TokenMiddleware $tokenMiddleware = new TokenMiddleware(),
        ?FamilyController $controller = new FamilyController()
    ) {
        $router->post('/family/invite', fn() => $tokenMiddleware->strict(), fn() => $controller->createInvite());
        $router->post('/family/join', fn() => $tokenMiddleware->strict(), fn() => $controller->joinInvite());
        $router->get('/family/children', fn() => $tokenMiddleware->strict(), fn() => $controller->getChildren());
        $router->get('/family/parent', fn() => $tokenMiddleware->strict(), fn() => $controller->getParent());
        $router->patch('/family/children/{id}', fn() => $tokenMiddleware->strict(), fn() => $controller->updateChildSettings());
        $router->delete('/family/links/{id}', fn() => $tokenMiddleware->strict(), fn() => $controller->unlink());
    }
}
