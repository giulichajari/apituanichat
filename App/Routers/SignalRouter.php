<?php

namespace App\Routers;

use App\Controllers\SignalController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;

class SignalRouter
{
    public function __construct(
        ?Router $router,
        ?TokenMiddleware $tokenMiddleware = new TokenMiddleware(),
        ?SignalController $signalController = new SignalController(),
    ) {

        // Guardar una offer
        $router->post(
            '/signal/offer',
            fn() => $tokenMiddleware->strict(),
            fn() => $signalController->addOffer()
        );

        // Obtener offer
        $router->get(
            '/signal/offer/{session_id}',
            fn() => $tokenMiddleware->strict(),
            fn() => $signalController->getOffer()
        );

        // Guardar una answer
        $router->post(
            '/signal/answer',
            fn() => $tokenMiddleware->strict(),
            fn() => $signalController->addAnswer()
        );

        // Obtener answer
        $router->get(
            '/signal/answer/{session_id}',
            fn() => $tokenMiddleware->strict(),
            fn() => $signalController->getAnswer()
        );

        // Guardar un candidate
        $router->post(
            '/signal/candidate',
            fn() => $tokenMiddleware->strict(),
            fn() => $signalController->addCandidate()
        );

        // Obtener todos los candidates
        $router->get(
            '/signal/candidates/{session_id}',
            fn() => $tokenMiddleware->strict(),
            fn() => $signalController->getCandidates()
        );
    }
}
