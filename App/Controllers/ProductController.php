<?php

namespace App\Controllers;

use App\Models\ProductModel;
use App\Models\CategoryModel;
use App\Models\CountryModel;
use App\Models\File;
use App\Services\FileUploadService;

use EasyProjects\SimpleRouter\Router;

class ProductController
{
    private FileUploadService $fileUploadService;


    public function __construct(
        private ?ProductModel $productModel = new ProductModel(),
        private ?CategoryModel $categoryModel = new CategoryModel(),
        private ?CountryModel $countryModel = new CountryModel()
    ) {
        $this->fileUploadService = new FileUploadService();
    }

    // ✅ Obtener productos por vendedor
    public function getProductsByOwner()
    {
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        if (!$userId) {
            Router::$response->status(401)->send(["message" => "Unauthorized"]);
            return;
        }

        $products = $this->productModel->getProductsByOwner($userId);

        Router::$response->status(200)->send([
            "success" => true,
            "data" => $products,
            "message" => "Products retrieved successfully"
        ]);
    }

    // ✅ Subir archivos para productos
    public function uploadFile()
    {
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        if (!$userId) {
            Router::$response->status(401)->send(["message" => "Unauthorized"]);
            return;
        }

        if (empty($_FILES) || !isset($_FILES['file'])) {
            Router::$response->status(400)->send(["message" => "No file uploaded"]);
            return;
        }

        $uploadedFile = $_FILES['file'];

        try {
            // Validar el archivo
            $validation = $this->fileUploadService->validateFile($uploadedFile);
            if (!$validation['success']) {
                Router::$response->status(400)->send(["message" => $validation['message']]);
                return;
            }

            // Subir el archivo
            $uploadResult = $this->fileUploadService->upload($uploadedFile, 'products', $userId);

            if (!$uploadResult['success']) {
                Router::$response->status(500)->send(["message" => $uploadResult['message']]);
                return;
            }

            Router::$response->status(201)->send([
                "success" => true,
                "message" => "File uploaded successfully",
                "file_url" => $uploadResult['file_url'],
                "file_name" => $uploadResult['file_name']
            ]);
        } catch (\Exception $e) {
            error_log("❌ Error in uploadFile: " . $e->getMessage());
            Router::$response->status(500)->send(["message" => "Internal server error"]);
        }
    }

    // ✅ Obtener producto por ID
    public function getProduct($id)
    {
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        $product = $this->productModel->getProduct($id, $userId);

        if (!$product) {
            Router::$response->status(404)->send(["message" => "Product not found"]);
            return;
        }

        Router::$response->status(200)->send([
            "success" => true,
            "data" => $product,
            "message" => "Product retrieved successfully"
        ]);
    }

    // ✅ Obtener todos los productos con filtros
    public function listProducts()
    {
        $query = Router::$request->query;
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        $filters = [
            'category_id' => $query->category_id ?? null,
            'country_id' => $query->country_id ?? null,
            'category_name' => $query->category_name ?? null,
            'country_name' => $query->country_name ?? null,
            'min_price' => $query->min_price ?? null,
            'max_price' => $query->max_price ?? null,
            'search' => $query->search ?? null,
            'location' => $query->location ?? null,
            'is_approved' => $query->is_approved ?? true,
            'is_active' => $query->is_active ?? true,
            'limit' => $query->limit ?? 20,
            'offset' => $query->offset ?? 0
        ];

        $products = $this->productModel->getProducts($filters, $userId);
        $total = $this->productModel->getProductsCount($filters);

        Router::$response->status(200)->send([
            "success" => true,
            "data" => $products,
            "pagination" => [
                "total" => $total,
                "limit" => $filters['limit'],
                "offset" => $filters['offset']
            ],
            "message" => "Products retrieved successfully"
        ]);
    }

    // ✅ Crear nuevo producto
    public function createProduct()
    {
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        if (!$userId) {
            Router::$response->status(401)->send(["message" => "Unauthorized"]);
            return;
        }

        $body = Router::$request->body;

        $productData = [
            'name' => $body->name ?? null,
            'description' => $body->description ?? null,
            'price' => $body->price ?? null,
            'category_id' => $body->category_id ?? null,
            'country_id' => $body->country_id ?? null,
            'stock_quantity' => $body->stock_quantity ?? 0,
            'weight' => $body->weight ?? 0,
            'dimensions' => $body->dimensions ?? null,
            'sku' => $body->sku ?? null,
            'image_url' => $body->image_url ?? null
        ];

        // Validar campos requeridos
        $required = ['name', 'price', 'category_id', 'country_id'];
        foreach ($required as $field) {
            if (empty($productData[$field])) {
                Router::$response->status(400)->send(["message" => "Missing required field: $field"]);
                return;
            }
        }

        $productId = $this->productModel->createProduct($productData, $userId);

        if ($productId) {
            Router::$response->status(201)->send([
                "success" => true,
                "product_id" => $productId,
                "message" => "Product created successfully"
            ]);
        } else {
            Router::$response->status(500)->send(["message" => "Error creating product"]);
        }
    }

    public function updateProduct($id)
    {
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        if (!$userId) {
            Router::$response->status(401)->send(["message" => "Unauthorized"]);
            return;
        }

        $body = Router::$request->body;

        // Solo actualizar los campos que vienen en el request
        $productData = [];
        $allowedFields = [
            'name',
            'description',
            'price',
            'category_id',
            'country_id',
            'stock_quantity',
            'weight',
            'dimensions',
            'sku',
            'image_url',
            'is_active'
        ];

        foreach ($allowedFields as $field) {
            if (isset($body->$field)) {
                $productData[$field] = $body->$field;
            }
        }

        // Verificar que al menos un campo fue proporcionado
        if (empty($productData)) {
            Router::$response->status(400)->send(["message" => "No fields to update"]);
            return;
        }

        // Verificar que el producto pertenezca al usuario
        $product = $this->productModel->getProduct($id, $userId);
        if (!$product) {
            Router::$response->status(404)->send(["message" => "Product not found or access denied"]);
            return;
        }

        $result = $this->productModel->updateProduct($id, $productData, $userId);

        if ($result) {
            Router::$response->status(200)->send([
                "success" => true,
                "message" => "Product updated successfully",
                "product" => $result
            ]);
        } else {
            Router::$response->status(500)->send(["message" => "Error updating product"]);
        }
    }

    // ✅ Actualizar parcialmente un producto
    public function partialUpdateProduct($id)
    {
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        if (!$userId) {
            Router::$response->status(401)->send(["message" => "Unauthorized"]);
            return;
        }

        $body = Router::$request->body;

        // Verificar que el producto pertenezca al usuario
        $product = $this->productModel->getProduct($id, $userId);
        if (!$product) {
            Router::$response->status(404)->send(["message" => "Product not found or access denied"]);
            return;
        }

        $result = $this->productModel->partialUpdateProduct($id, (array)$body, $userId);

        if ($result) {
            Router::$response->status(200)->send([
                "success" => true,
                "message" => "Product updated successfully"
            ]);
        } else {
            Router::$response->status(500)->send(["message" => "Error updating product"]);
        }
    }

    // ✅ Eliminar producto
    public function deleteProduct($id)
    {
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        if (!$userId) {
            Router::$response->status(401)->send(["message" => "Unauthorized"]);
            return;
        }

        // Verificar que el producto pertenezca al usuario
        $product = $this->productModel->getProduct($id, $userId);
        if (!$product) {
            Router::$response->status(404)->send(["message" => "Product not found or access denied"]);
            return;
        }

        $result = $this->productModel->deleteProduct($id, $userId);

        if ($result) {
            Router::$response->status(200)->send([
                "success" => true,
                "message" => "Product deleted successfully"
            ]);
        } else {
            Router::$response->status(500)->send(["message" => "Error deleting product"]);
        }
    }

    // ✅ Obtener productos por categoría
    public function getProductsByCategory($categoryId)
    {
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        $products = $this->productModel->getProductsByCategory($categoryId, $userId);

        Router::$response->status(200)->send([
            "success" => true,
            "data" => $products,
            "message" => "Products by category retrieved successfully"
        ]);
    }

    // ✅ Obtener productos por categoría (por nombre)
    public function getProductsByCategoryName($categoryName)
    {
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        $products = $this->productModel->getProductsByCategoryName($categoryName, $userId);

        Router::$response->status(200)->send([
            "success" => true,
            "data" => $products,
            "message" => "Products by category name retrieved successfully"
        ]);
    }

    // ✅ Obtener productos por país
    public function getProductsByCountry($countryId)
    {
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        $products = $this->productModel->getProductsByCountry($countryId, $userId);

        Router::$response->status(200)->send([
            "success" => true,
            "data" => $products,
            "message" => "Products by country retrieved successfully"
        ]);
    }

    // ✅ Obtener productos por país (por nombre)
    public function getProductsByCountryName($countryName)
    {
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        $products = $this->productModel->getProductsByCountryName($countryName, $userId);

        Router::$response->status(200)->send([
            "success" => true,
            "data" => $products,
            "message" => "Products by country name retrieved successfully"
        ]);
    }

    // ✅ Buscar productos por nombre o descripción
    public function searchProducts($query)
    {
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        $products = $this->productModel->searchProducts($query, $userId);

        Router::$response->status(200)->send([
            "success" => true,
            "data" => $products,
            "message" => "Products search completed successfully"
        ]);
    }

    // ✅ Búsqueda avanzada con múltiples parámetros
    public function advancedSearchProducts()
    {
        $query = Router::$request->query;
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        $filters = [
            'search' => $query->search ?? null,
            'category_id' => $query->category_id ?? null,
            'country_id' => $query->country_id ?? null,
            'min_price' => $query->min_price ?? null,
            'max_price' => $query->max_price ?? null,
            'location' => $query->location ?? null,
            'rating' => $query->rating ?? null,
            'limit' => $query->limit ?? 20,
            'offset' => $query->offset ?? 0
        ];

        $products = $this->productModel->advancedSearchProducts($filters, $userId);
        $total = $this->productModel->getAdvancedSearchCount($filters);

        Router::$response->status(200)->send([
            "success" => true,
            "data" => $products,
            "pagination" => [
                "total" => $total,
                "limit" => $filters['limit'],
                "offset" => $filters['offset']
            ],
            "message" => "Advanced search completed successfully"
        ]);
    }

    // ✅ Obtener productos por ubicación (ciudad)
    public function getProductsByLocation($ciudad)
    {
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        $products = $this->productModel->getProductsByLocation($ciudad, $userId);

        Router::$response->status(200)->send([
            "success" => true,
            "data" => $products,
            "message" => "Products by location retrieved successfully"
        ]);
    }

    // ✅ Subir/actualizar imagen principal del producto
    public function updateMainImage($id)
    {
        try {
            // LOG TEMPORAL - Esto seguro funcionará
            $logMessage = "=== UPLOAD DEBUG " . date('Y-m-d H:i:s') . " ===\n";
            $logMessage .= "Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'Unknown') . "\n";
            $logMessage .= "Files received: " . print_r($_FILES, true) . "\n";
            $logMessage .= "Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'Not set') . "\n";
            $logMessage .= "Product ID: " . $id . "\n";
            $logMessage .= "User ID: " . (Router::$request->user->id ?? 'No user') . "\n";

            // Escribir en un archivo temporal
            file_put_contents('/var/www/apituanichat/php-error.log', $logMessage, FILE_APPEND);

            $user = Router::$request->user;
            $userId = $user->id ?? null;

            if (!$userId) {
                file_put_contents('/var/www/apituanichat/php-error.log', "❌ Unauthorized\n", FILE_APPEND);
                Router::$response->status(401)->send(["message" => "Unauthorized"]);
                return;
            }

            if (empty($_FILES) || !isset($_FILES['image'])) {
                $debugInfo = "FILES keys: " . implode(', ', array_keys($_FILES)) . "\n";
                file_put_contents('/var/www/apituanichat/php-error.log', "❌ No image: " . $debugInfo, FILE_APPEND);
                Router::$response->status(400)->send(["message" => "No image uploaded"]);
                return;
            }

            // Resto de tu código...
            file_put_contents('/var/www/apituanichat/php-error.log', "✅ Starting file processing\n", FILE_APPEND);

            $uploadedFile = $_FILES['image'];

            // Continuar con el proceso...

        } catch (\Exception $e) {
            $errorMsg = "❌ Exception: " . $e->getMessage() . "\n";
            file_put_contents('/var/www/apituanichat/php-error.log', $errorMsg, FILE_APPEND);
            Router::$response->status(500)->send(["message" => "Internal server error"]);
        }
    }

    // ✅ Gestión de imágenes múltiples del producto
    public function addProductImage($id)
    {
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        if (!$userId) {
            Router::$response->status(401)->send(["message" => "Unauthorized"]);
            return;
        }

        if (empty($_FILES) || !isset($_FILES['image'])) {
            Router::$response->status(400)->send(["message" => "No image uploaded"]);
            return;
        }

        // Verificar que el producto pertenezca al usuario
        $product = $this->productModel->getProduct($id, $userId);
        if (!$product) {
            Router::$response->status(404)->send(["message" => "Product not found or access denied"]);
            return;
        }

        $uploadedFile = $_FILES['image'];

        try {
            $uploadResult = $this->fileUploadService->upload($uploadedFile, 'products', $userId);

            if (!$uploadResult['success']) {
                Router::$response->status(500)->send(["message" => $uploadResult['message']]);
                return;
            }

            // Agregar imagen adicional al producto
            $imageId = $this->productModel->addProductImage($id, $uploadResult['file_url'], $userId);

            if ($imageId) {
                Router::$response->status(201)->send([
                    "success" => true,
                    "image_id" => $imageId,
                    "image_url" => $uploadResult['file_url'],
                    "message" => "Product image added successfully"
                ]);
            } else {
                Router::$response->status(500)->send(["message" => "Error adding product image"]);
            }
        } catch (\Exception $e) {
            error_log("❌ Error in addProductImage: " . $e->getMessage());
            Router::$response->status(500)->send(["message" => "Internal server error"]);
        }
    }

    // ✅ Eliminar imagen del producto
    public function deleteProductImage($id, $imageId)
    {
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        if (!$userId) {
            Router::$response->status(401)->send(["message" => "Unauthorized"]);
            return;
        }

        $result = $this->productModel->deleteProductImage($id, $imageId, $userId);

        if ($result) {
            Router::$response->status(200)->send([
                "success" => true,
                "message" => "Product image deleted successfully"
            ]);
        } else {
            Router::$response->status(500)->send(["message" => "Error deleting product image"]);
        }
    }

    // ✅ Establecer imagen como principal
    public function setPrimaryImage($id, $imageId)
    {
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        if (!$userId) {
            Router::$response->status(401)->send(["message" => "Unauthorized"]);
            return;
        }

        $result = $this->productModel->setPrimaryImage($id, $imageId, $userId);

        if ($result) {
            Router::$response->status(200)->send([
                "success" => true,
                "message" => "Primary image set successfully"
            ]);
        } else {
            Router::$response->status(500)->send(["message" => "Error setting primary image"]);
        }
    }

    // ✅ Obtener productos favoritos del usuario
    public function getFavoriteProducts()
    {
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        if (!$userId) {
            Router::$response->status(401)->send(["message" => "Unauthorized"]);
            return;
        }

        $favorites = $this->productModel->getFavoriteProducts($userId);

        Router::$response->status(200)->send([
            "success" => true,
            "data" => $favorites,
            "message" => "Favorite products retrieved successfully"
        ]);
    }

    // ✅ Agregar/eliminar producto de favoritos
    public function toggleFavorite($id)
    {
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        if (!$userId) {
            Router::$response->status(401)->send(["message" => "Unauthorized"]);
            return;
        }

        $result = $this->productModel->toggleFavorite($id, $userId);

        Router::$response->status(200)->send([
            "success" => true,
            "is_favorite" => $result,
            "message" => $result ? "Product added to favorites" : "Product removed from favorites"
        ]);
    }

    // ✅ Obtener todas las categorías
    public function getAllCategories()
    {
        $categories = $this->categoryModel->getAllCategories();

        Router::$response->status(200)->send([
            "success" => true,
            "data" => $categories,
            "message" => "Categories retrieved successfully"
        ]);
    }

    // ✅ Obtener todos los países
    public function getAllCountries()
    {
        $countries = $this->countryModel->getAllCountries();

        Router::$response->status(200)->send([
            "success" => true,
            "data" => $countries,
            "message" => "Countries retrieved successfully"
        ]);
    }

    // ✅ Actualizar stock
    public function updateStock($id)
    {
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        if (!$userId) {
            Router::$response->status(401)->send(["message" => "Unauthorized"]);
            return;
        }

        $body = Router::$request->body;
        $stockQuantity = $body->stock_quantity ?? null;

        if ($stockQuantity === null) {
            Router::$response->status(400)->send(["message" => "Missing stock_quantity"]);
            return;
        }

        $result = $this->productModel->updateStock($id, $stockQuantity, $userId);

        if ($result) {
            Router::$response->status(200)->send([
                "success" => true,
                "message" => "Stock updated successfully"
            ]);
        } else {
            Router::$response->status(500)->send(["message" => "Error updating stock"]);
        }
    }

    // ✅ Obtener productos más vendidos
    public function getBestSellers()
    {
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        $products = $this->productModel->getBestSellers($userId);

        Router::$response->status(200)->send([
            "success" => true,
            "data" => $products,
            "message" => "Best sellers retrieved successfully"
        ]);
    }

    // ✅ Obtener productos nuevos
    public function getNewArrivals()
    {
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        $products = $this->productModel->getNewArrivals($userId);

        Router::$response->status(200)->send([
            "success" => true,
            "data" => $products,
            "message" => "New arrivals retrieved successfully"
        ]);
    }

    // ✅ Obtener productos en oferta
    public function getProductsOnSale()
    {
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        $products = $this->productModel->getProductsOnSale($userId);

        Router::$response->status(200)->send([
            "success" => true,
            "data" => $products,
            "message" => "Products on sale retrieved successfully"
        ]);
    }

    // ✅ Obtener reviews de un producto
    public function getProductReviews($id)
    {
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        $reviews = $this->productModel->getProductReviews($id, $userId);

        Router::$response->status(200)->send([
            "success" => true,
            "data" => $reviews,
            "message" => "Product reviews retrieved successfully"
        ]);
    }

    // ✅ Agregar review a un producto
    public function addProductReview($id)
    {
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        if (!$userId) {
            Router::$response->status(401)->send(["message" => "Unauthorized"]);
            return;
        }

        $body = Router::$request->body;

        $reviewData = [
            'rating' => $body->rating ?? null,
            'comment' => $body->comment ?? null
        ];

        if (!$reviewData['rating']) {
            Router::$response->status(400)->send(["message" => "Missing rating"]);
            return;
        }

        $reviewId = $this->productModel->addProductReview($id, $reviewData, $userId);

        if ($reviewId) {
            Router::$response->status(201)->send([
                "success" => true,
                "review_id" => $reviewId,
                "message" => "Review added successfully"
            ]);
        } else {
            Router::$response->status(500)->send(["message" => "Error adding review"]);
        }
    }

    // ✅ Actualizar review de un producto
    public function updateProductReview($id, $reviewId)
    {
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        if (!$userId) {
            Router::$response->status(401)->send(["message" => "Unauthorized"]);
            return;
        }

        $body = Router::$request->body;

        $reviewData = [
            'rating' => $body->rating ?? null,
            'comment' => $body->comment ?? null
        ];

        $result = $this->productModel->updateProductReview($id, $reviewId, $reviewData, $userId);

        if ($result) {
            Router::$response->status(200)->send([
                "success" => true,
                "message" => "Review updated successfully"
            ]);
        } else {
            Router::$response->status(500)->send(["message" => "Error updating review"]);
        }
    }

    // ✅ Eliminar review de un producto
    public function deleteProductReview($id, $reviewId)
    {
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        if (!$userId) {
            Router::$response->status(401)->send(["message" => "Unauthorized"]);
            return;
        }

        $result = $this->productModel->deleteProductReview($id, $reviewId, $userId);

        if ($result) {
            Router::$response->status(200)->send([
                "success" => true,
                "message" => "Review deleted successfully"
            ]);
        } else {
            Router::$response->status(500)->send(["message" => "Error deleting review"]);
        }
    }

    // ✅ Estadísticas para vendedores
    public function getOwnerStats()
    {
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        if (!$userId) {
            Router::$response->status(401)->send(["message" => "Unauthorized"]);
            return;
        }

        $stats = $this->productModel->getOwnerStats($userId);

        Router::$response->status(200)->send([
            "success" => true,
            "data" => $stats,
            "message" => "Owner stats retrieved successfully"
        ]);
    }

    // ✅ Obtener productos pendientes de aprobación (solo admin)
    public function getPendingApproval()
    {
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        if (!$userId) {
            Router::$response->status(401)->send(["message" => "Unauthorized"]);
            return;
        }

        // Verificar si el usuario es admin (debes implementar esta verificación)
        if (!$this->isAdmin($userId)) {
            Router::$response->status(403)->send(["message" => "Forbidden: Admin access required"]);
            return;
        }

        $products = $this->productModel->getPendingApproval();

        Router::$response->status(200)->send([
            "success" => true,
            "data" => $products,
            "message" => "Pending approval products retrieved successfully"
        ]);
    }

    // ✅ Aprobar/rechazar producto (solo admin)
    public function updateApprovalStatus($id)
    {
        $user = Router::$request->user;
        $userId = $user->id ?? null;

        if (!$userId) {
            Router::$response->status(401)->send(["message" => "Unauthorized"]);
            return;
        }

        // Verificar si el usuario es admin
        if (!$this->isAdmin($userId)) {
            Router::$response->status(403)->send(["message" => "Forbidden: Admin access required"]);
            return;
        }

        $body = Router::$request->body;
        $isApproved = $body->is_approved ?? null;
        $rejectionReason = $body->rejection_reason ?? null;

        if ($isApproved === null) {
            Router::$response->status(400)->send(["message" => "Missing is_approved"]);
            return;
        }

        $result = $this->productModel->updateApprovalStatus($id, $isApproved, $rejectionReason);

        if ($result) {
            Router::$response->status(200)->send([
                "success" => true,
                "message" => "Approval status updated successfully"
            ]);
        } else {
            Router::$response->status(500)->send(["message" => "Error updating approval status"]);
        }
    }

    // ✅ Método auxiliar para verificar si es admin (debes implementarlo según tu sistema)
    private function isAdmin($userId)
    {
        // Implementa la lógica para verificar si el usuario es admin
        // Por ejemplo:
        // return $this->userModel->isAdmin($userId);
        return true; // Temporal - implementa según tu sistema
    }
}
