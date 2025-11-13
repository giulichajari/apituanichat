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
        // IMPORTANTE: Rutas ordenadas de más específicas a más generales

        // ==================== RUTAS ESPECÍFICAS CON MÚLTIPOS PARÁMETROS ====================

        // Gestión de reviews específicas
        $router->put(
            '/products/{id}/reviews/{reviewId}',
            fn() => $tokenMiddleware->strict(),
            fn($id, $reviewId) => $productController->updateProductReview($id, $reviewId)
        );

        $router->delete(
            '/products/{id}/reviews/{reviewId}',
            fn() => $tokenMiddleware->strict(),
            fn($id, $reviewId) => $productController->deleteProductReview($id, $reviewId)
        );

        // Gestión de imágenes específicas
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

        // ==================== RUTAS CON SUBRUTAS ESPECÍFICAS ====================

        // Subir archivos para productos - rutas específicas primero
        $router->post(
            '/upload/product-image',
            fn() => $tokenMiddleware->strict(),
            fn() => $productController->uploadProductImage()
        );

        $router->post(
            '/products/upload',
            fn() => $tokenMiddleware->strict(),
            fn() => $productController->uploadFile()
        );

        // Gestión de imágenes del producto
        $router->post(
            '/products/{id}/images',
            fn() => $tokenMiddleware->strict(),
            fn($id) => $productController->addProductImage($id)
        );

        $router->post(
            '/products/{id}/main-image',
            fn() => $tokenMiddleware->strict(),
            fn($id) => $productController->updateMainImage($id)
        );

        // Reviews de productos
        $router->get(
            '/products/{id}/reviews',
            fn() => $tokenMiddleware->optional(),
            fn($id) => $productController->getProductReviews($id)
        );

        $router->post(
            '/products/{id}/reviews',
            fn() => $tokenMiddleware->strict(),
            fn($id) => $productController->addProductReview($id)
        );

        // Favoritos
        $router->post(
            '/products/{id}/favorite',
            fn() => $tokenMiddleware->strict(),
            fn($id) => $productController->toggleFavorite($id)
        );

        // Stock
        $router->patch(
            '/products/{id}/stock',
            fn() => $tokenMiddleware->strict(),
            fn($id) => $productController->updateStock($id)
        );

        // Aprobación (admin)
        $router->patch(
            '/products/{id}/approval',
            fn() => $tokenMiddleware->strict(),
            fn($id) => $productController->updateApprovalStatus($id)
        );

        // ==================== RUTAS CON PARÁMETROS NUMÉRICOS ====================

        // Producto por ID numérico
        $router->get(
            '/products/{id:\d+}',
            fn() => $tokenMiddleware->optional(),
            fn($id) => $productController->getProduct($id)
        );

        // Productos por categoría ID
        $router->get(
            '/products/category/{categoryId:\d+}',
            fn() => $tokenMiddleware->optional(),
            fn($categoryId) => $productController->getProductsByCategory($categoryId)
        );

        // Productos por país ID
        $router->get(
            '/products/country/{countryId:\d+}',
            fn() => $tokenMiddleware->optional(),
            fn($countryId) => $productController->getProductsByCountry($countryId)
        );

        // ==================== RUTAS CON PARÁMETROS DE TEXTO ====================

        // Productos por nombre de categoría
        $router->get(
            '/products/category/name/{categoryName}',
            fn() => $tokenMiddleware->optional(),
            fn($categoryName) => $productController->getProductsByCategoryName($categoryName)
        );

        // Productos por nombre de país
        $router->get(
            '/products/country/name/{countryName}',
            fn() => $tokenMiddleware->optional(),
            fn($countryName) => $productController->getProductsByCountryName($countryName)
        );

        // Productos por ubicación
        $router->get(
            '/products/location/{ciudad}',
            fn() => $tokenMiddleware->optional(),
            fn($ciudad) => $productController->getProductsByLocation($ciudad)
        );

        // Búsqueda de productos
        $router->get(
            '/products/search/{query}',
            fn() => $tokenMiddleware->optional(),
            fn($query) => $productController->searchProducts($query)
        );

        // ==================== RUTAS DE COLECCIONES ESPECÍFICAS ====================

        // Productos del propietario (vendedor)
        $router->get(
            '/products/owner',
            fn() => $tokenMiddleware->strict(),
            fn() => $productController->getProductsByOwner()
        );

        // Productos favoritos
        $router->get(
            '/products/favorites',
            fn() => $tokenMiddleware->strict(),
            fn() => $productController->getFavoriteProducts()
        );

        // Productos pendientes de aprobación
        $router->get(
            '/products/pending-approval',
            fn() => $tokenMiddleware->strict(),
            fn() => $productController->getPendingApproval()
        );

        // Estadísticas del propietario
        $router->get(
            '/products/owner/stats',
            fn() => $tokenMiddleware->strict(),
            fn() => $productController->getOwnerStats()
        );

        // Categorías
        $router->get(
            '/products/categories/all',
            fn() => $tokenMiddleware->optional(),
            fn() => $productController->getAllCategories()
        );

        // Países
        $router->get(
            '/products/countries/all',
            fn() => $tokenMiddleware->optional(),
            fn() => $productController->getAllCountries()
        );

        // Productos trending
        $router->get(
            '/products/trending/best-sellers',
            fn() => $tokenMiddleware->optional(),
            fn() => $productController->getBestSellers()
        );

        $router->get(
            '/products/trending/new-arrivals',
            fn() => $tokenMiddleware->optional(),
            fn() => $productController->getNewArrivals()
        );

        $router->get(
            '/products/trending/on-sale',
            fn() => $tokenMiddleware->optional(),
            fn() => $productController->getProductsOnSale()
        );

        // ==================== RUTAS DE OPERACIONES CRUD BÁSICAS ====================

        // Actualización parcial
        $router->patch(
            '/products/{id}',
            fn() => $tokenMiddleware->strict(),
            fn($id) => $productController->partialUpdateProduct($id)
        );

        // Actualización completa
        $router->put(
            '/products/{id}',
            fn() => $tokenMiddleware->strict(),
            fn($id) => $productController->updateProduct($id)
        );

        // Eliminar producto
        $router->delete(
            '/products/{id}',
            fn() => $tokenMiddleware->strict(),
            fn($id) => $productController->deleteProduct($id)
        );

        // ==================== RUTAS GENERALES (ÚLTIMAS) ====================

        // Búsqueda avanzada (múltiples parámetros query)
        $router->get(
            '/products/advanced/search',
            fn() => $tokenMiddleware->optional(),
            fn() => $productController->advancedSearchProducts()
        );

        // Crear nuevo producto
        $router->post(
            '/products',
            fn() => $tokenMiddleware->strict(),
            fn() => $productController->createProduct()
        );

        // Listar todos los productos (ruta más general - ÚLTIMA)
        $router->get(
            '/products',
            fn() => $tokenMiddleware->optional(),
            fn() => $productController->listProducts()
        );
    }
}