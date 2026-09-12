<?php
namespace App\Routers;
use App\Controllers\ShopStoreController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;
class ShopStoresRouter
{
    public function __construct(
        ?Router $router,
        ?TokenMiddleware $tokenMiddleware = null,
        ?ShopStoreController $controller = null
    ) {
        $tokenMiddleware = $tokenMiddleware ?? new TokenMiddleware();
        $controller = $controller ?? new ShopStoreController();

        // Publica: tiendas aprobadas (mapa) -- antes de rutas con {id}
        $router->get(
            '/shop/stores',
            fn() => $controller->getApprovedStores()
        );

        // Puntos GPS pendientes (admin) -- antes de rutas con {id}
        $router->get(
            '/shop/stores/pending-location-approval',
            fn() => $tokenMiddleware->strict(),
            fn() => $controller->getPendingLocationApprovals()
        );

        $router->patch(
            '/shop/stores/{id:\d+}/location-approval',
            fn() => $tokenMiddleware->strict(),
            fn() => $controller->updateLocationApproval((int) self::param('id'))
        );

        // Tienda del vendedor logueado
        $router->get(
            '/shop/store',
            fn() => $tokenMiddleware->strict(),
            fn() => $controller->getMyStore()
        );
        $router->post(
            '/shop/store',
            fn() => $tokenMiddleware->strict(),
            fn() => $controller->createOrUpdateStore()
        );

        // Fotos de la tienda
        $router->post(
            '/shop/store/cover',
            fn() => $tokenMiddleware->strict(),
            fn() => $controller->uploadCoverPhoto()
        );
        $router->post(
            '/shop/store/images',
            fn() => $tokenMiddleware->strict(),
            fn() => $controller->uploadStoreImage()
        );
        $router->get(
            '/shop/store/images',
            fn() => $tokenMiddleware->strict(),
            fn() => $controller->getMyStoreImages()
        );
        $router->delete(
            '/shop/store/images/{id:\d+}',
            fn() => $tokenMiddleware->strict(),
            fn() => $controller->deleteStoreImage((int) self::param('id'))
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
