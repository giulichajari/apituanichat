<?php

namespace App\Routers;

use App\Controllers\ProductController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;

class ProductsRouter
{
    public function __construct(
        ?Router $router,
        ?TokenMiddleware $tokenMiddleware = new TokenMiddleware(),
        ?ProductController $productController = new ProductController()
    ) {
        // IMPORTANTE: Colocar las rutas más específicas PRIMERO

        // Obtener productos por vendedor - DEBE IR ANTES de la ruta con {id}
        $router->get(
            '/products/owner',
            fn() => $tokenMiddleware->strict(),
            fn() => $productController->getProductsByOwner()
        );

        // Subir archivos para productos
        $router->post(
            '/products/upload',
            fn() => $tokenMiddleware->strict(),
            fn() => $productController->uploadFile()
        );
        $router->post(
            '/upload/product-image',
            fn() => $tokenMiddleware->strict(),
            fn() => $productController->uploadProductImage()
        );
        // Obtener producto por ID
        $router->get(
            '/products/{id:\d+}', // SOLO números para IDs
            fn() => $tokenMiddleware->optional(),
            fn($id) => $productController->getProduct($id)
        );

        // Obtener todos los productos con filtros
        $router->get(
            '/products',
            fn() => $tokenMiddleware->optional(),
            fn() => $productController->listProducts()
        );

        // Crear nuevo producto
        $router->post(
            '/products',
            fn() => $tokenMiddleware->strict(),
            fn() => $productController->createProduct()
        );

     // En tu archivo de rutas, agrega esto temporalmente
$router->put(
    '/products/{id}',
    function($id) {
        $logFile = __DIR__ . '/../../php-error.log';
        error_log("🎯 ROUTE HIT - PUT /products/{$id}\n", 3, $logFile);
        error_log("🕐 " . date('Y-m-d H:i:s') . "\n", 3, $logFile);
        error_log("📝 Headers: " . print_r(getallheaders(), true) . "\n", 3, $logFile);
    },
    fn() => $tokenMiddleware->strict(),
    fn($id) => $productController->updateProduct($id)
);

        // Actualizar parcialmente un producto
        $router->patch(
            '/products/{id}',
            fn() => $tokenMiddleware->strict(),
            fn($id) => $productController->partialUpdateProduct($id)
        );

        // Eliminar producto
        $router->delete(
            '/products/{id}',
            fn() => $tokenMiddleware->strict(),
            fn($id) => $productController->deleteProduct($id)
        );

        // Obtener productos por categoría
        $router->get(
            '/products/category/{categoryId:\d+}',
            fn() => $tokenMiddleware->optional(),
            fn($categoryId) => $productController->getProductsByCategory($categoryId)
        );

        // Obtener productos por categoría (por nombre)
        $router->get(
            '/products/category/name/{categoryName}',
            fn() => $tokenMiddleware->optional(),
            fn($categoryName) => $productController->getProductsByCategoryName($categoryName)
        );

        // Obtener productos por país
        $router->get(
            '/products/country/{countryId:\d+}',
            fn() => $tokenMiddleware->optional(),
            fn($countryId) => $productController->getProductsByCountry($countryId)
        );

        // Obtener productos por país (por nombre)
        $router->get(
            '/products/country/name/{countryName}',
            fn() => $tokenMiddleware->optional(),
            fn($countryName) => $productController->getProductsByCountryName($countryName)
        );

        // Buscar productos por nombre o descripción
        $router->get(
            '/products/search/{query}',
            fn() => $tokenMiddleware->optional(),
            fn($query) => $productController->searchProducts($query)
        );

        // Búsqueda avanzada con múltiples parámetros
        $router->get(
            '/products/advanced/search',
            fn() => $tokenMiddleware->optional(),
            fn() => $productController->advancedSearchProducts()
        );

        // Obtener productos por ubicación (ciudad)
        $router->get(
            '/products/location/{ciudad}',
            fn() => $tokenMiddleware->optional(),
            fn($ciudad) => $productController->getProductsByLocation($ciudad)
        );

        // Subir/actualizar imagen principal del producto
        $router->post(
            '/products/{id}/main-image',
            fn() => $tokenMiddleware->strict(),
            fn($id) => $productController->updateMainImage($id)
        );

        // Gestión de imágenes múltiples del producto
        $router->post(
            '/products/{id}/images',
            fn() => $tokenMiddleware->strict(),
            fn($id) => $productController->addProductImage($id)
        );

        $router->delete(
            '/products/{id}/images/{imageId}',
            fn() => $tokenMiddleware->strict(),
            fn($id, $imageId) => $productController->deleteProductImage($id, $imageId)
        );

        $router->patch(
            '/products/{id}/images/{imageId}/primary',
            fn() => $tokenMiddleware->strict(),
            fn($id, $imageId) => $productController->setPrimaryImage($id, $imageId)
        );

        // Obtener productos favoritos del usuario
        $router->get(
            '/products/favorites',
            fn() => $tokenMiddleware->strict(),
            fn() => $productController->getFavoriteProducts()
        );

        // Agregar/eliminar producto de favoritos
        $router->post(
            '/products/{id}/favorite',
            fn() => $tokenMiddleware->strict(),
            fn($id) => $productController->toggleFavorite($id)
        );

        // Obtener categorías de productos
        $router->get(
            '/products/categories/all',
            fn() => $tokenMiddleware->optional(),
            fn() => $productController->getAllCategories()
        );

        // Obtener países disponibles
        $router->get(
            '/products/countries/all',
            fn() => $tokenMiddleware->optional(),
            fn() => $productController->getAllCountries()
        );

        // Gestión de stock
        $router->patch(
            '/products/{id}/stock',
            fn() => $tokenMiddleware->strict(),
            fn($id) => $productController->updateStock($id)
        );

        // Obtener productos más vendidos
        $router->get(
            '/products/trending/best-sellers',
            fn() => $tokenMiddleware->optional(),
            fn() => $productController->getBestSellers()
        );

        // Obtener productos nuevos
        $router->get(
            '/products/trending/new-arrivals',
            fn() => $tokenMiddleware->optional(),
            fn() => $productController->getNewArrivals()
        );

        // Obtener productos en oferta
        $router->get(
            '/products/trending/on-sale',
            fn() => $tokenMiddleware->optional(),
            fn() => $productController->getProductsOnSale()
        );

        // Obtener reviews de un producto
        $router->get(
            '/products/{id}/reviews',
            fn() => $tokenMiddleware->optional(),
            fn($id) => $productController->getProductReviews($id)
        );

        // Agregar review a un producto
        $router->post(
            '/products/{id}/reviews',
            fn() => $tokenMiddleware->strict(),
            fn($id) => $productController->addProductReview($id)
        );

        // Actualizar review de un producto
        $router->put(
            '/products/{id}/reviews/{reviewId}',
            fn() => $tokenMiddleware->strict(),
            fn($id, $reviewId) => $productController->updateProductReview($id, $reviewId)
        );

        // Eliminar review de un producto
        $router->delete(
            '/products/{id}/reviews/{reviewId}',
            fn() => $tokenMiddleware->strict(),
            fn($id, $reviewId) => $productController->deleteProductReview($id, $reviewId)
        );

        // Estadísticas para vendedores
        $router->get(
            '/products/owner/stats',
            fn() => $tokenMiddleware->strict(),
            fn() => $productController->getOwnerStats()
        );

        // Obtener productos pendientes de aprobación (solo admin)
        $router->get(
            '/products/pending-approval',
            fn() => $tokenMiddleware->strict(),
            fn() => $productController->getPendingApproval()
        );

        // Aprobar/rechazar producto (solo admin)
        $router->patch(
            '/products/{id}/approval',
            fn() => $tokenMiddleware->strict(),
            fn($id) => $productController->updateApprovalStatus($id)
        );
    }
}
