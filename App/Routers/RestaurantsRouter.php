<?php

namespace App\Routers;

use App\Controllers\RestaurantController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;

class RestaurantsRouter
{
    /** Obtiene un param de ruta; SimpleRouter guarda la clave con el regex p.ej. "id:\d+" */
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
        ?RestaurantController $restaurantController = new RestaurantController()
    ) {
        // IMPORTANTE: Colocar las rutas más específicas PRIMERO

        // Servir imagen de portada (busca en public y en uploads legacy)
        $router->get(
            '/restaurants/cover/{filename:.+}',
            function () use ($restaurantController) {
                $p = Router::$request->params ?? null;
                $arr = $p ? (array) $p : [];
                $filename = (string) (
                    $arr['filename']
                    ?? $arr['filename:.+']
                    ?? (count($arr) ? reset($arr) : '')
                );
                $restaurantController->serveRestaurantCover($filename);
            }
        );

        // Obtener restaurantes por propietario - DEBE IR ANTES de la ruta con {id}
        $router->get(
            '/restaurants/owner',
            fn() => $tokenMiddleware->strict(),
            fn() => $restaurantController->getRestaurantsByOwner()
        );

        // Puntos GPS pendientes (admin) — antes de rutas con {id}
        $router->get(
            '/restaurants/pending-location-approval',
            fn() => $tokenMiddleware->strict(),
            fn() => $restaurantController->getPendingLocationApprovals()
        );

        $router->patch(
            '/restaurants/{id:\d+}/location-approval',
            fn() => $tokenMiddleware->strict(),
            fn() => $restaurantController->updateLocationApproval((int) self::param('id'))
        );

  $router->post(
            '/restaurants/upload',
            fn() => $tokenMiddleware->strict(),
            fn() => $restaurantController->uploadFile()
        );

        // Obtener restaurante por ID
        $router->get(
            '/restaurants/{id:\d+}', // SOLO números para IDs
            fn() => $tokenMiddleware->optional(),
            fn() => $restaurantController->getRestaurant((int) self::param('id'))
        );
        // Obtener todos los restaurantes
        $router->get(
            '/restaurants',
            fn() => $tokenMiddleware->optional(),
            fn() => $restaurantController->listRestaurants()
        );

        // Crear nuevo restaurante
        $router->post(
            '/restaurants',
            fn() => $tokenMiddleware->strict(),
            fn() => $restaurantController->createRestaurant()
        );

        // Actualizar restaurante completo
        $router->put(
            '/restaurants/{id}',
            fn() => $tokenMiddleware->strict(),
            fn() => $restaurantController->updateRestaurant((int) self::param('id'))
        );

        // Actualizar parcialmente un restaurante
        $router->patch(
            '/restaurants/{id}',
            fn() => $tokenMiddleware->strict(),
            fn() => $restaurantController->partialUpdateRestaurant((int) self::param('id'))
        );

        // Eliminar restaurante
        $router->delete(
            '/restaurants/{id}',
            fn() => $tokenMiddleware->strict(),
            fn() => $restaurantController->deleteRestaurant((int) self::param('id'))
        );

        // Obtener restaurantes por tipo de comida
        $router->get(
            '/restaurants/category/{tipoComida}',
            fn() => $tokenMiddleware->optional(),
            fn() => $restaurantController->getRestaurantsByCategory((string) self::param('tipoComida'))
        );

        // Buscar restaurantes por nombre o ubicación
        $router->get(
            '/restaurants/search/{query}',
            fn() => $tokenMiddleware->optional(),
            fn() => $restaurantController->searchRestaurants((string) self::param('query'))
        );

        // Obtener restaurantes por ubicación (ciudad)
        $router->get(
            '/restaurants/location/{ciudad}',
            fn() => $tokenMiddleware->optional(),
            fn() => $restaurantController->getRestaurantsByLocation((string) self::param('ciudad'))
        );

        // Subir/actualizar foto de portada
        $router->post(
            '/restaurants/{id}/cover-image',
            fn() => $tokenMiddleware->strict(),
            fn() => $restaurantController->updateCoverImage((int) self::param('id'))
        );

        // Obtener restaurantes favoritos del usuario
        $router->get(
            '/restaurants/favorites',
            fn() => $tokenMiddleware->strict(),
            fn() => $restaurantController->getFavoriteRestaurants()
        );

        // Agregar/eliminar restaurante de favoritos
        $router->post(
            '/restaurants/{id}/favorite',
            fn() => $tokenMiddleware->strict(),
            fn() => $restaurantController->toggleFavorite((int) self::param('id'))
        );
    

     $router->get(
            '/restaurants/{restaurantId:\d+}/dishes',
            fn() => $tokenMiddleware->optional(),
            fn() => $restaurantController->getRestaurantDishes((int) self::param('restaurantId'))
        );

        // Obtener un plato específico
        $router->get(
            '/restaurants/{restaurantId:\d+}/dishes/{dishId:\d+}',
            fn() => $tokenMiddleware->optional(),
            fn() => $restaurantController->getRestaurantDish((int) self::param('restaurantId'), (int) self::param('dishId'))
        );

        // Crear nuevo plato
        $router->post(
            '/restaurants/{restaurantId:\d+}/dishes',
            fn() => $tokenMiddleware->strict(),
            fn() => $restaurantController->createRestaurantDish((int) self::param('restaurantId'))
        );

        // Actualizar plato completo
        $router->put(
            '/restaurants/{restaurantId:\d+}/dishes/{dishId:\d+}',
            fn() => $tokenMiddleware->strict(),
            fn() => $restaurantController->updateRestaurantDish((int) self::param('restaurantId'), (int) self::param('dishId'))
        );

        // Actualizar parcialmente un plato
        $router->patch(
            '/restaurants/{restaurantId:\d+}/dishes/{dishId:\d+}',
            fn() => $tokenMiddleware->strict(),
            fn() => $restaurantController->partialUpdateRestaurantDish((int) self::param('restaurantId'), (int) self::param('dishId'))
        );

        // Eliminar plato
        $router->delete(
            '/restaurants/{restaurantId:\d+}/dishes/{dishId:\d+}',
            fn() => $tokenMiddleware->strict(),
            fn() => $restaurantController->deleteRestaurantDish((int) self::param('restaurantId'), (int) self::param('dishId'))
        );

        // Subir imagen de plato
        $router->post(
            '/restaurants/{restaurantId:\d+}/dishes/{dishId:\d+}/image',
            fn() => $tokenMiddleware->strict(),
            fn() => $restaurantController->updateRestaurantDishImage((int) self::param('restaurantId'), (int) self::param('dishId'))
        );

        // Obtener platos por categoría
        $router->get(
            '/restaurants/{restaurantId:\d+}/dishes/category/{category}',
            fn() => $tokenMiddleware->optional(),
            fn() => $restaurantController->getRestaurantDishesByCategory((int) self::param('restaurantId'), (string) self::param('category'))
        );
    }
}
