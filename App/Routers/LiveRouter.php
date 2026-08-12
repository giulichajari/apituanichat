<?php
namespace App\Routers;

use App\Controllers\LiveController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;

class LiveRouter
{
    public function __construct(
        ?Router $router,
        ?TokenMiddleware $tokenMiddleware = new TokenMiddleware(),
        ?LiveController $liveController = new LiveController()
    ) {
        $router->post(
            '/groups/{idGroup}/live/start',
            fn() => $tokenMiddleware->strict(),
            fn() => $liveController->startLive()
        );

        $router->get(
            '/groups/{idGroup}/live/current',
            fn() => $tokenMiddleware->strict(),
            fn() => $liveController->getCurrentLive()
        );

        $router->post('/live/on-publish', fn() => $liveController->onPublish());
        $router->post('/live/on-publish-done', fn() => $liveController->onPublishDone());
    }
}
