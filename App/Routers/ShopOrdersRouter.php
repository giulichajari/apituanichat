<?php
namespace App\Routers;
use App\Controllers\ShopOrderController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;
class ShopOrdersRouter
{
    public function __construct(
        ?Router $router,
        ?TokenMiddleware $tokenMiddleware = null,
        ?ShopOrderController $controller = null
    ) {
        $tokenMiddleware = $tokenMiddleware ?? new TokenMiddleware();
        $controller = $controller ?? new ShopOrderController();

        $router->post(
            '/shop/orders',
            fn() => $tokenMiddleware->strict(),
            fn() => $controller->checkout()
        );

        // Historial de compras del comprador logueado -- antes de rutas con {id}
        $router->get(
            '/shop/orders/my-purchases',
            fn() => $tokenMiddleware->strict(),
            fn() => $controller->getMyPurchases()
        );

        // Dashboard del vendedor -- antes de rutas con {id}
        $router->get(
            '/shop/orders/pending-shipments',
            fn() => $tokenMiddleware->strict(),
            fn() => $controller->getPendingShipments()
        );

        $router->get(
            '/shop/orders/seller-stats',
            fn() => $tokenMiddleware->strict(),
            fn() => $controller->getSellerStats()
        );

        $router->patch(
            '/shop/orders/{id:\d+}/ship',
            fn() => $tokenMiddleware->strict(),
            fn() => $controller->markAsShipped((int) self::param('id'))
        );
    }

    /** Obtiene un param de ruta; SimpleRouter guarda la clave con el regex p.ej. "id:\d+" */
    private static function param(string $name, string $suffix = ':\d+'): mixed
    {
        $p = Router::$request->params ?? null;
        if ($p === null) return null;
        $arr = (array) $p;
        return $arr[$name] ?? $arr[$name . $suffix] ?? null;
    }
}
