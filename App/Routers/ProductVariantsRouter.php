<?php
namespace App\Routers;
use App\Controllers\ProductVariantController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;
class ProductVariantsRouter
{
    public function __construct(
        ?Router $router,
        ?TokenMiddleware $tokenMiddleware = null,
        ?ProductVariantController $controller = null
    ) {
        $tokenMiddleware = $tokenMiddleware ?? new TokenMiddleware();
        $controller = $controller ?? new ProductVariantController();

        // Publica: variantes activas de un producto (ficha del producto / visor 3D)
        $router->get(
            '/products/{id:\d+}/variants',
            fn() => $controller->getVariants((int) self::param('id'))
        );

        // Vendedor: todas sus variantes (incluye inactivas) para un producto propio
        $router->get(
            '/shop/products/{id:\d+}/variants',
            fn() => $tokenMiddleware->strict(),
            fn() => $controller->getMyVariants((int) self::param('id'))
        );

        // Vendedor: crear variante nueva para un producto propio
        $router->post(
            '/shop/products/{id:\d+}/variants',
            fn() => $tokenMiddleware->strict(),
            fn() => $controller->createVariant((int) self::param('id'))
        );

        // Vendedor: actualizar una variante propia
        $router->patch(
            '/shop/variants/{id:\d+}',
            fn() => $tokenMiddleware->strict(),
            fn() => $controller->updateVariant((int) self::param('id'))
        );

        // Vendedor: subir el .glb de una variante propia
        $router->post(
            '/shop/variants/{id:\d+}/model',
            fn() => $tokenMiddleware->strict(),
            fn() => $controller->uploadModel((int) self::param('id'))
        );

        // Vendedor: desactivar (borrado logico) una variante propia
        $router->delete(
            '/shop/variants/{id:\d+}',
            fn() => $tokenMiddleware->strict(),
            fn() => $controller->deleteVariant((int) self::param('id'))
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
