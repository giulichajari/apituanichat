<?php

namespace App\Routers;

use App\Controllers\EncuestasController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;

class EncuestasRouter
{
    public function __construct(
        ?Router $router,
        ?TokenMiddleware $tokenMiddleware = null,
        ?EncuestasController $encuestasController = null
    ) {
        $tokenMiddleware = $tokenMiddleware ?? new TokenMiddleware();
        $encuestasController = $encuestasController ?? new EncuestasController();

        // Subir foto de una opcion de encuesta
        $router->post(
            '/group/{idGroup}/encuestas/upload-foto',
            fn() => $tokenMiddleware->strict(),
            fn() => $encuestasController->uploadOpcionFoto()
        );

        // Crear una encuesta en un grupo
        $router->post(
            '/group/{idGroup}/encuestas',
            fn() => $tokenMiddleware->strict(),
            fn() => $encuestasController->createEncuesta()
        );

        // Listar encuestas de un grupo
        $router->get(
            '/group/{idGroup}/encuestas',
            fn() => $tokenMiddleware->strict(),
            fn() => $encuestasController->getEncuestas()
        );

        // Votar en una encuesta
        $router->post(
            '/group/{idGroup}/encuestas/{idEncuesta}/vote',
            fn() => $tokenMiddleware->strict(),
            fn() => $encuestasController->vote()
        );

        // Crear link de pago para desbloquear una encuesta privada
        $router->post(
            '/group/{idGroup}/encuestas/{idEncuesta}/pay',
            fn() => $tokenMiddleware->strict(),
            fn() => $encuestasController->createEncuestaPayment()
        );
    }
}
