<?php

namespace App\Routers;

use App\Controllers\OrderController;
use App\Controllers\SquareWebhookController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;

class OrdersRouter
{
    public function __construct(
        ?Router $router,
        ?TokenMiddleware $tokenMiddleware = new TokenMiddleware(),
        ?OrderController $orderController = new OrderController(),
        ?SquareWebhookController $webhookController = new SquareWebhookController()
    ) {
        // Webhook de Square (público, sin autenticación)
        $router->post('/webhooks/square', fn() => $webhookController->handle());

        $router->post(
            '/orders/food',
            fn() => $tokenMiddleware->strict(),
            fn() => $orderController->createFoodOrder()
        );

        $router->get(
            '/orders/restaurant/{restaurantId:\d+}',
            fn() => $tokenMiddleware->strict(),
            fn() => $orderController->getOrdersByRestaurant((int)(Router::$request->params->restaurantId ?? 0))
        );

        $router->patch(
            '/orders/{orderId:\d+}/confirm',
            fn() => $tokenMiddleware->strict(),
            fn() => $orderController->confirmOrder((int)(Router::$request->params->orderId ?? 0))
        );
    }
}
