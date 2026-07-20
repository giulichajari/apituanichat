<?php

namespace App\Routers;

use App\Controllers\CountryRateController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;

class CountryRatesRouter
{
    public function __construct(
        ?Router $router,
        ?TokenMiddleware $tokenMiddleware = new TokenMiddleware(),
        ?CountryRateController $controller = new CountryRateController()
    ) {
        $router->get(
            '/country-rates',
            fn() => $tokenMiddleware->optional(),
            fn() => $controller->listRates()
        );

        $router->get(
            '/country-rates/by-alpha2/{code}',
            fn() => $tokenMiddleware->optional(),
            fn() => $controller->getByAlpha2()
        );

        $router->post(
            '/country-rates/seed',
            fn() => $tokenMiddleware->strict(),
            fn() => $controller->seedRates()
        );

        $router->put(
            '/country-rates/{id}',
            fn() => $tokenMiddleware->strict(),
            fn() => $controller->updateRate()
        );
    }
}
