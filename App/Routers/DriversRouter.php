<?php

namespace App\Routers;

use App\Controllers\DriverController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;

class DriversRouter
{
    public function __construct(
        ?Router $router,
        ?TokenMiddleware $tokenMiddleware = new TokenMiddleware(),
        ?DriverController $driverController = new DriverController()
    ) {
        // Obtener todos los choferes
        // Listar todos los choferes
        $router->get(
            '/drivers',
            fn() => $tokenMiddleware->strict(),
            fn() => $driverController->listDrivers()
        );

        // Obtener su propio chofer por userId (query param)
        $router->get(
            '/drivers/driversprofile',
            fn() => $tokenMiddleware->strict(),
            fn() => $driverController->getDriver()
        );
        $router->patch(
            '/drivers/availability',
            fn() => $tokenMiddleware->strict(),
            fn() => $driverController->updateAvailability()
        );
        // Obtener choferes disponibles en una ciudad (solo lectura)
        $router->get(
            '/drivers/available',
            fn() => $tokenMiddleware->strict(),
            fn() => $driverController->getAvailableDriversByCity()
        );

        // Crear chofer
        $router->post(
            '/drivers',
            fn() => $tokenMiddleware->strict(),
            fn() => $driverController->createDriver()
        );

        $router->put(
            '/drivers',
            fn() => $tokenMiddleware->strict(),
            fn() => $driverController->updateDriver()
        );

        // Actualizar solo imagen de perfil
        $router->post(
            '/drivers/profile-image',
            fn() => $tokenMiddleware->strict(),
            fn() => $driverController->updateProfileImage()
        );
        $router->post(
            '/drivers/request',
            fn() => $tokenMiddleware->strict(),
            fn() => $driverController->requestDriver()
        );
    }
}
