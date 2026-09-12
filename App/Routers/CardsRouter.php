<?php
namespace App\Routers;

use App\Controllers\CardController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;

class CardsRouter
{
    public function __construct(
        ?Router $router,
        ?TokenMiddleware $tokenMiddleware = new TokenMiddleware(),
        ?CardController $cardController = new CardController()
    ) {
        $router->get(
            '/cards/config',
            fn() => $tokenMiddleware->strict(),
            fn() => $cardController->getSquareConfig()
        );
        $router->get(
            '/cards',
            fn() => $tokenMiddleware->strict(),
            fn() => $cardController->listCards()
        );
        $router->post(
            '/cards',
            fn() => $tokenMiddleware->strict(),
            fn() => $cardController->addCard()
        );
        $router->delete(
            '/cards/{id}',
            fn() => $tokenMiddleware->strict(),
            fn($id) => $cardController->deleteCard($id)
        );
        $router->patch(
            '/cards/{id}/default',
            fn() => $tokenMiddleware->strict(),
            fn($id) => $cardController->setDefaultCard($id)
        );
    }
}
