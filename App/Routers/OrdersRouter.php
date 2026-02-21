<?php

namespace App\Routers;

use App\Controllers\OrderController;
use App\Controllers\SquareWebhookController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;

class OrdersRouter
{
    /** Parámetro de ruta; SimpleRouter guarda la clave con el regex p.ej. "restaurantId:\d+" */
    private static function param(string $name, string $suffix = ':\d+'): mixed
    {
        $p = Router::$request->params ?? null;
        if ($p === null) return null;
        $arr = (array) $p;
        return $arr[$name] ?? $arr[$name . $suffix] ?? null;
    }

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
            fn() => $orderController->getOrdersByRestaurant((int) self::param('restaurantId'))
        );

        $router->patch(
            '/orders/{orderId:\d+}/confirm',
            fn() => $tokenMiddleware->strict(),
            fn() => $orderController->confirmOrder((int) self::param('orderId'))
        );
    }
}
