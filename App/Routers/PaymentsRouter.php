<?php

namespace App\Routers;

use App\Controllers\PaymentController;
use App\Controllers\RideCheckoutController;
use App\Controllers\RideActivityController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;

class PaymentsRouter
{
    public function __construct(
        ?Router $router,
        ?TokenMiddleware $tokenMiddleware = new TokenMiddleware(),
        ?PaymentController $paymentController = new PaymentController()
    ) {
        $router->post(
            '/payments/create-link',
            fn() => $tokenMiddleware->strict(),
            fn() => (new RideCheckoutController())->purchase()
        );

        $router->post('/payments/ride-quote', fn() => $tokenMiddleware->strict(), fn() => (new RideCheckoutController())->quote());

        $router->get('/payments/ride-history', fn() => $tokenMiddleware->strict(), fn() => (new RideActivityController())->history());
        $router->post('/payments/ride-action', fn() => $tokenMiddleware->strict(), fn() => (new RideActivityController())->transition());
        $router->patch(
            '/payments/{id}/status',
            fn() => $tokenMiddleware->strict(),
            fn($id) => $paymentController->updateStatus($id)
        );

        $router->get(
            '/payments/user/{userId}',
            fn() => $tokenMiddleware->strict(),
            fn($userId) => $paymentController->getPaymentsByUser($userId)
        );

        $router->get(
            '/payments/driver/{driverId}',
            fn() => $tokenMiddleware->strict(),
            fn($driverId) => $paymentController->getPaymentsByDriver($driverId)
        );
    }
}
