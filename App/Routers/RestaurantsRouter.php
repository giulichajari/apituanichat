<?php

namespace App\Routers;

use App\Controllers\RestaurantController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;

class RestaurantsRouter
{
    public function __construct(
        ?Router $router,
        ?TokenMiddleware $tokenMiddleware = new TokenMiddleware(),
        ?RestaurantController $restaurantController = new RestaurantController()
    ) {
        // IMPORTANTE: Colocar las rutas más específicas PRIMERO

        // Servir imagen de portada (busca en public y en uploads legacy)
        $router->get(
            '/restaurants/cover/{filename:.+}',
            fn() => $restaurantController->serveRestaurantCover(Router::$request->params->filename ?? '')
        );

        // Obtener restaurantes por propietario - DEBE IR ANTES de la ruta con {id}
        $router->get(
            '/restaurants/owner',
            fn() => $tokenMiddleware->strict(),
            fn() => $restaurantController->getRestaurantsByOwner()
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
            fn() => $restaurantController->getRestaurant((int)(Router::$request->params->id ?? 0))
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
            fn() => $restaurantController->updateRestaurant((int)(Router::$request->params->id ?? 0))
        );

        // Actualizar parcialmente un restaurante
        $router->patch(
            '/restaurants/{id}',
            fn() => $tokenMiddleware->strict(),
            fn() => $restaurantController->partialUpdateRestaurant((int)(Router::$request->params->id ?? 0))
        );

        // Eliminar restaurante
        $router->delete(
            '/restaurants/{id}',
            fn() => $tokenMiddleware->strict(),
            fn() => $restaurantController->deleteRestaurant((int)(Router::$request->params->id ?? 0))
        );

        // Obtener restaurantes por tipo de comida
        $router->get(
            '/restaurants/category/{tipoComida}',
            fn() => $tokenMiddleware->optional(),
            fn() => $restaurantController->getRestaurantsByCategory(Router::$request->params->tipoComida ?? '')
        );

        // Buscar restaurantes por nombre o ubicación
        $router->get(
            '/restaurants/search/{query}',
            fn() => $tokenMiddleware->optional(),
            fn() => $restaurantController->searchRestaurants(Router::$request->params->query ?? '')
        );

        // Obtener restaurantes por ubicación (ciudad)
        $router->get(
            '/restaurants/location/{ciudad}',
            fn() => $tokenMiddleware->optional(),
            fn() => $restaurantController->getRestaurantsByLocation(Router::$request->params->ciudad ?? '')
        );

        // Subir/actualizar foto de portada
        $router->post(
            '/restaurants/{id}/cover-image',
            fn() => $tokenMiddleware->strict(),
            fn() => $restaurantController->updateCoverImage((int)(Router::$request->params->id ?? 0))
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
            fn() => $restaurantController->toggleFavorite((int)(Router::$request->params->id ?? 0))
        );
    

     $router->get(
            '/restaurants/{restaurantId:\d+}/dishes',
            fn() => $tokenMiddleware->optional(),
            fn() => $restaurantController->getRestaurantDishes((int)(Router::$request->params->restaurantId ?? 0))
        );

        // Obtener un plato específico
        $router->get(
            '/restaurants/{restaurantId:\d+}/dishes/{dishId:\d+}',
            fn() => $tokenMiddleware->optional(),
            fn() => $restaurantController->getRestaurantDish((int)(Router::$request->params->restaurantId ?? 0), (int)(Router::$request->params->dishId ?? 0))
        );

        // Crear nuevo plato
        $router->post(
            '/restaurants/{restaurantId:\d+}/dishes',
            fn() => $tokenMiddleware->strict(),
            fn() => $restaurantController->createRestaurantDish((int)(Router::$request->params->restaurantId ?? 0))
        );

        // Actualizar plato completo
        $router->put(
            '/restaurants/{restaurantId:\d+}/dishes/{dishId:\d+}',
            fn() => $tokenMiddleware->strict(),
            fn() => $restaurantController->updateRestaurantDish((int)(Router::$request->params->restaurantId ?? 0), (int)(Router::$request->params->dishId ?? 0))
        );

        // Actualizar parcialmente un plato
        $router->patch(
            '/restaurants/{restaurantId:\d+}/dishes/{dishId:\d+}',
            fn() => $tokenMiddleware->strict(),
            fn() => $restaurantController->partialUpdateRestaurantDish((int)(Router::$request->params->restaurantId ?? 0), (int)(Router::$request->params->dishId ?? 0))
        );

        // Eliminar plato
        $router->delete(
            '/restaurants/{restaurantId:\d+}/dishes/{dishId:\d+}',
            fn() => $tokenMiddleware->strict(),
            fn() => $restaurantController->deleteRestaurantDish((int)(Router::$request->params->restaurantId ?? 0), (int)(Router::$request->params->dishId ?? 0))
        );

        // Subir imagen de plato
        $router->post(
            '/restaurants/{restaurantId:\d+}/dishes/{dishId:\d+}/image',
            fn() => $tokenMiddleware->strict(),
            fn() => $restaurantController->updateRestaurantDishImage((int)(Router::$request->params->restaurantId ?? 0), (int)(Router::$request->params->dishId ?? 0))
        );

        // Obtener platos por categoría
        $router->get(
            '/restaurants/{restaurantId:\d+}/dishes/category/{category}',
            fn() => $tokenMiddleware->optional(),
            fn() => $restaurantController->getRestaurantDishesByCategory((int)(Router::$request->params->restaurantId ?? 0), Router::$request->params->category ?? '')
        );
    }
}
