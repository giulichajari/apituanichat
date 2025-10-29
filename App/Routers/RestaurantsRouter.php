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

        // Obtener restaurantes por propietario - DEBE IR ANTES de la ruta con {id}
        $router->get(
            '/restaurants/owner',
            fn() => $tokenMiddleware->strict(),
            fn() => $restaurantController->getRestaurantsByOwner()
        );
  $router->post(
            '/upload',
            fn() => $tokenMiddleware->strict(),
            fn() => $restaurantController->uploadFile()
        );

        // Obtener restaurante por ID
        $router->get(
            '/restaurants/{id:\d+}', // SOLO números para IDs
            fn() => $tokenMiddleware->optional(),
            fn($id) => $restaurantController->getRestaurant($id)
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
            fn($id) => $restaurantController->updateRestaurant($id)
        );

        // Actualizar parcialmente un restaurante
        $router->patch(
            '/restaurants/{id}',
            fn() => $tokenMiddleware->strict(),
            fn($id) => $restaurantController->partialUpdateRestaurant($id)
        );

        // Eliminar restaurante
        $router->delete(
            '/restaurants/{id}',
            fn() => $tokenMiddleware->strict(),
            fn($id) => $restaurantController->deleteRestaurant($id)
        );

        // Obtener restaurantes por tipo de comida
        $router->get(
            '/restaurants/category/{tipoComida}',
            fn() => $tokenMiddleware->optional(),
            fn($tipoComida) => $restaurantController->getRestaurantsByCategory($tipoComida)
        );

        // Buscar restaurantes por nombre o ubicación
        $router->get(
            '/restaurants/search/{query}',
            fn() => $tokenMiddleware->optional(),
            fn($query) => $restaurantController->searchRestaurants($query)
        );

        // Obtener restaurantes por ubicación (ciudad)
        $router->get(
            '/restaurants/location/{ciudad}',
            fn() => $tokenMiddleware->optional(),
            fn($ciudad) => $restaurantController->getRestaurantsByLocation($ciudad)
        );

        // Subir/actualizar foto de portada
        $router->post(
            '/restaurants/{id}/cover-image',
            fn() => $tokenMiddleware->strict(),
            fn($id) => $restaurantController->updateCoverImage($id)
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
            fn($id) => $restaurantController->toggleFavorite($id)
        );
    

     $router->get(
            '/restaurants/{restaurantId:\d+}/dishes',
            fn() => $tokenMiddleware->optional(),
            fn($restaurantId) => $restaurantController->getRestaurantDishes($restaurantId)
        );

        // Obtener un plato específico
        $router->get(
            '/restaurants/{restaurantId:\d+}/dishes/{dishId:\d+}',
            fn() => $tokenMiddleware->optional(),
            fn($restaurantId, $dishId) => $restaurantController->getRestaurantDish($restaurantId, $dishId)
        );

        // Crear nuevo plato
        $router->post(
            '/restaurants/{restaurantId:\d+}/dishes',
            fn() => $tokenMiddleware->strict(),
            fn($restaurantId) => $restaurantController->createRestaurantDish($restaurantId)
        );

        // Actualizar plato completo
        $router->put(
            '/restaurants/{restaurantId:\d+}/dishes/{dishId:\d+}',
            fn() => $tokenMiddleware->strict(),
            fn($restaurantId, $dishId) => $restaurantController->updateRestaurantDish($restaurantId, $dishId)
        );

        // Actualizar parcialmente un plato
        $router->patch(
            '/restaurants/{restaurantId:\d+}/dishes/{dishId:\d+}',
            fn() => $tokenMiddleware->strict(),
            fn($restaurantId, $dishId) => $restaurantController->partialUpdateRestaurantDish($restaurantId, $dishId)
        );

        // Eliminar plato
        $router->delete(
            '/restaurants/{restaurantId:\d+}/dishes/{dishId:\d+}',
            fn() => $tokenMiddleware->strict(),
            fn($restaurantId, $dishId) => $restaurantController->deleteRestaurantDish($restaurantId, $dishId)
        );

        // Subir imagen de plato
        $router->post(
            '/restaurants/{restaurantId:\d+}/dishes/{dishId:\d+}/image',
            fn() => $tokenMiddleware->strict(),
            fn($restaurantId, $dishId) => $restaurantController->updateRestaurantDishImage($restaurantId, $dishId)
        );

        // Obtener platos por categoría
        $router->get(
            '/restaurants/{restaurantId:\d+}/dishes/category/{category}',
            fn() => $tokenMiddleware->optional(),
            fn($restaurantId, $category) => $restaurantController->getRestaurantDishesByCategory($restaurantId, $category)
        );
    }
}
