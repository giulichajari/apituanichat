<?php

namespace App\Models;

use App\Configs\Database;
use PDO;

class ProductModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Obtener productos por vendedor
     */
    public function getProductsByOwner(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                p.*,
                c.name as category_name,
                co.name as country_name,
                co.code as country_code,
                u.name as seller_name
            FROM products p
            JOIN categories c ON p.category_id = c.id
            JOIN countries co ON p.country_id = co.id
            JOIN users u ON p.seller_id = u.id
            WHERE p.seller_id = ? AND p.is_active = 1
            ORDER BY p.created_at DESC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtener producto por ID
     */
  public function getProduct($id)
{
    try {
        $productId = (int)$id;
        
        $stmt = $this->db->prepare("
            SELECT p.*, 
                   c.name as category_name,
                   co.name as country_name
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN countries co ON p.country_id = co.id  
            LEFT JOIN users u ON p.seller_id = u.id
            WHERE p.id = ?
        ");
        
        $stmt->execute([$productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $product ?: null;
        
    } catch (\Exception $e) {
        error_log("Error en getProduct model: " . $e->getMessage());
        return null;
    }
}
public function getProductBySku($sku)
{
    try {
        $stmt = $this->db->prepare("SELECT * FROM products WHERE sku = ?");
        $stmt->execute([$sku]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\Exception $e) {
        error_log("Error en getProductBySku: " . $e->getMessage());
        return null;
    }
}
    /**
     * Obtener productos con filtros
     */
    public function getProducts(array $filters = [], ?int $userId = null): array
    {
        $whereConditions = ["p.is_active = 1"];
        $params = [];

        // Mostrar productos aprobados; si hay userId, también los propios (pendientes o no)
        if ($this->isAdmin($userId)) {
            // Admin ve todos
        } elseif ($userId) {
            $whereConditions[] = "(p.is_approved = 1 OR p.seller_id = ?)";
            $params[] = $userId;
        } else {
            $whereConditions[] = "p.is_approved = 1";
        }

        // Aplicar filtros básicos
        if (!empty($filters['category_id'])) {
            $whereConditions[] = "p.category_id = ?";
            $params[] = (int)$filters['category_id'];
        }

        if (!empty($filters['country_id'])) {
            $whereConditions[] = "p.country_id = ?";
            $params[] = (int)$filters['country_id'];
        }

        if (!empty($filters['category_name'])) {
            $whereConditions[] = "c.name = ?";
            $params[] = $filters['category_name'];
        }

        if (!empty($filters['country_name'])) {
            $whereConditions[] = "co.name = ?";
            $params[] = $filters['country_name'];
        }

        if (!empty($filters['min_price'])) {
            $whereConditions[] = "p.price >= ?";
            $params[] = (float)$filters['min_price'];
        }

        if (!empty($filters['max_price'])) {
            $whereConditions[] = "p.price <= ?";
            $params[] = (float)$filters['max_price'];
        }

        if (!empty($filters['search'])) {
            $whereConditions[] = "(p.name LIKE ? OR p.description LIKE ?)";
            $searchTerm = "%{$filters['search']}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $sql = "
            SELECT 
                p.*,
                c.name as category_name,
                co.name as country_name,
                co.code as country_code,
                u.name as seller_name
            FROM products p
            JOIN categories c ON p.category_id = c.id
            JOIN countries co ON p.country_id = co.id
            JOIN users u ON p.seller_id = u.id
        ";

        if (!empty($whereConditions)) {
            $sql .= " WHERE " . implode(" AND ", $whereConditions);
        }

        $sql .= " ORDER BY p.created_at DESC";

        // Aplicar límite y offset directamente
        $limit = !empty($filters['limit']) ? (int)$filters['limit'] : 20;
        $offset = !empty($filters['offset']) ? (int)$filters['offset'] : 0;
        
        $sql .= " LIMIT $limit OFFSET $offset";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Contar productos con filtros
     */
    public function getProductsCount(array $filters = [], ?int $userId = null): int
    {
        $whereConditions = ["p.is_active = 1"];
        $params = [];

        if ($this->isAdmin($userId)) {
            // Admin cuenta todos
        } elseif ($userId) {
            $whereConditions[] = "(p.is_approved = 1 OR p.seller_id = ?)";
            $params[] = $userId;
        } else {
            $whereConditions[] = "p.is_approved = 1";
        }

        // Aplicar filtros
        if (!empty($filters['category_id'])) {
            $whereConditions[] = "p.category_id = ?";
            $params[] = (int)$filters['category_id'];
        }

        if (!empty($filters['country_id'])) {
            $whereConditions[] = "p.country_id = ?";
            $params[] = (int)$filters['country_id'];
        }

        if (!empty($filters['search'])) {
            $whereConditions[] = "(p.name LIKE ? OR p.description LIKE ?)";
            $searchTerm = "%{$filters['search']}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $sql = "SELECT COUNT(*) as total FROM products p";
        
        if (!empty($whereConditions)) {
            $sql .= " WHERE " . implode(" AND ", $whereConditions);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result['total'] ?? 0;
    }

    /**
     * Crear nuevo producto
     */
    public function createProduct(array $data, int $userId): ?int
    {
        try {
            $sql = "INSERT INTO products 
                (name, description, price, category_id, country_id, seller_id, 
                 stock_quantity, weight, dimensions, sku, image_url)
                VALUES 
                (:name, :description, :price, :category_id, :country_id, :seller_id,
                 :stock_quantity, :weight, :dimensions, :sku, :image_url)";

            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                ':name' => $data['name'],
                ':description' => $data['description'] ?? null,
                ':price' => $data['price'],
                ':category_id' => $data['category_id'],
                ':country_id' => $data['country_id'],
                ':seller_id' => $userId,
                ':stock_quantity' => $data['stock_quantity'] ?? 0,
                ':weight' => $data['weight'] ?? 0,
                ':dimensions' => $data['dimensions'] ?? null,
                ':sku' => $data['sku'] ?? $this->generateSku($data['name']),
                ':image_url' => $data['image_url'] ?? null
            ]);

            return $success ? $this->db->lastInsertId() : null;
        } catch (\PDOException $e) {
            error_log("❌ Error al crear producto: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Actualizar producto
     */
public function updateProduct(int $productId, array $data, int $userId): bool
{
    $logFile = __DIR__ . '/../../php-error.log';
    
    try {
        error_log("🔄 === INICIANDO ACTUALIZACIÓN DE PRODUCTO ===", 3, $logFile);
        error_log("📝 Product ID: " . $productId . "\n", 3, $logFile);
        error_log("👤 User ID: " . $userId . "\n", 3, $logFile);
        error_log("📦 Datos recibidos: " . print_r($data, true) . "\n", 3, $logFile);

        $fields = [];
        $params = [':id' => $productId, ':user_id' => $userId];

        $allowedFields = [
            'name', 'description', 'price', 'category_id', 'country_id',
            'stock_quantity', 'weight', 'dimensions', 'sku', 'image_url', 'is_active'
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
                error_log("✅ Campo a actualizar: $field = " . $data[$field] . "\n", 3, $logFile);
            }
        }

        if (empty($fields)) {
            error_log("❌ No hay campos válidos para actualizar\n", 3, $logFile);
            return false;
        }

        error_log("📋 Campos a actualizar: " . implode(', ', $fields) . "\n", 3, $logFile);
        error_log("🔑 Parámetros: " . print_r($params, true) . "\n", 3, $logFile);

        $sql = "UPDATE products SET " . implode(', ', $fields) . " 
                WHERE id = :id AND seller_id = :user_id";
        
        error_log("🗃️ SQL generado: " . $sql . "\n", 3, $logFile);

        $stmt = $this->db->prepare($sql);
        
        if (!$stmt) {
            $errorInfo = print_r($this->db->errorInfo(), true);
            error_log("❌ Error preparando la consulta: " . $errorInfo . "\n", 3, $logFile);
            return false;
        }

        $result = $stmt->execute($params);
        
        if ($result) {
            error_log("✅ Producto actualizado exitosamente\n", 3, $logFile);
            error_log("📊 Filas afectadas: " . $stmt->rowCount() . "\n", 3, $logFile);
        } else {
            $errorInfo = print_r($stmt->errorInfo(), true);
            error_log("❌ Error ejecutando la consulta: " . $errorInfo . "\n", 3, $logFile);
            error_log("🔍 Información completa del error: " . print_r([
                'errorCode' => $stmt->errorCode(),
                'errorInfo' => $stmt->errorInfo(),
                'params' => $params
            ], true) . "\n", 3, $logFile);
        }

        return $result;

    } catch (\PDOException $e) {
        error_log("💥 EXCEPCIÓN PDO en updateProduct:\n", 3, $logFile);
        error_log("📌 Mensaje: " . $e->getMessage() . "\n", 3, $logFile);
        error_log("📌 Código: " . $e->getCode() . "\n", 3, $logFile);
        error_log("📌 Archivo: " . $e->getFile() . "\n", 3, $logFile);
        error_log("📌 Línea: " . $e->getLine() . "\n", 3, $logFile);
        error_log("📌 Trace: " . $e->getTraceAsString() . "\n", 3, $logFile);
        return false;
    } catch (\Exception $e) {
        error_log("💥 EXCEPCIÓN GENERAL en updateProduct:\n", 3, $logFile);
        error_log("📌 Mensaje: " . $e->getMessage() . "\n", 3, $logFile);
        error_log("📌 Trace: " . $e->getTraceAsString() . "\n", 3, $logFile);
        return false;
    }
}

    /**
     * Eliminar producto (borrado lógico)
     */
    public function deleteProduct(int $productId, int $userId): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE products 
                SET is_active = 0 
                WHERE id = ? AND seller_id = ?
            ");
            return $stmt->execute([$productId, $userId]);
        } catch (\PDOException $e) {
            error_log("❌ Error al eliminar producto: " . $e->getMessage());
            return false;
        }
    }

    // Métodos simplificados
    public function getProductsByCategory(int $categoryId, ?int $userId = null): array
    {
        $filters = ['category_id' => $categoryId];
        return $this->getProducts($filters, $userId);
    }

    public function getProductsByCategoryName(string $categoryName, ?int $userId = null): array
    {
        $filters = ['category_name' => $categoryName];
        return $this->getProducts($filters, $userId);
    }

    public function getProductsByCountry(int $countryId, ?int $userId = null): array
    {
        $filters = ['country_id' => $countryId];
        return $this->getProducts($filters, $userId);
    }

    public function getProductsByCountryName(string $countryName, ?int $userId = null): array
    {
        $filters = ['country_name' => $countryName];
        return $this->getProducts($filters, $userId);
    }

    public function searchProducts(string $query, ?int $userId = null): array
    {
        $filters = ['search' => $query];
        return $this->getProducts($filters, $userId);
    }

    public function advancedSearchProducts(array $filters, ?int $userId = null): array
    {
        return $this->getProducts($filters, $userId);
    }

    public function getAdvancedSearchCount(array $filters): int
    {
        return $this->getProductsCount($filters);
    }

    // Métodos para gestión de imágenes
    public function updateProductImage(int $productId, string $imageUrl, int $userId): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE products 
                SET image_url = ? 
                WHERE id = ? AND seller_id = ?
            ");
            return $stmt->execute([$imageUrl, $productId, $userId]);
        } catch (\PDOException $e) {
            error_log("❌ Error al actualizar imagen del producto: " . $e->getMessage());
            return false;
        }
    }

    public function addProductImage(int $productId, string $imageUrl, int $userId): ?int
    {
        try {
            // Verificar que el producto pertenezca al usuario
            $product = $this->getProduct($productId, $userId);
            if (!$product) {
                return null;
            }

            $sql = "INSERT INTO product_images (product_id, image_url, is_primary) 
                    VALUES (:product_id, :image_url, :is_primary)";
            
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                ':product_id' => $productId,
                ':image_url' => $imageUrl,
                ':is_primary' => 0
            ]);

            return $success ? $this->db->lastInsertId() : null;
        } catch (\PDOException $e) {
            error_log("❌ Error al agregar imagen del producto: " . $e->getMessage());
            return null;
        }
    }

    public function deleteProductImage(int $productId, int $imageId, int $userId): bool
    {
        try {
            // Verificar que el producto pertenezca al usuario
            $product = $this->getProduct($productId, $userId);
            if (!$product) {
                return false;
            }

            $stmt = $this->db->prepare("
                DELETE FROM product_images 
                WHERE id = ? AND product_id = ?
            ");
            return $stmt->execute([$imageId, $productId]);
        } catch (\PDOException $e) {
            error_log("❌ Error al eliminar imagen del producto: " . $e->getMessage());
            return false;
        }
    }

    public function setPrimaryImage(int $productId, int $imageId, int $userId): bool
    {
        try {
            // Verificar que el producto pertenezca al usuario
            $product = $this->getProduct($productId, $userId);
            if (!$product) {
                return false;
            }

            // Primero quitar todas las imágenes principales
            $stmt = $this->db->prepare("
                UPDATE product_images 
                SET is_primary = 0 
                WHERE product_id = ?
            ");
            $stmt->execute([$productId]);

            // Luego establecer la nueva imagen como principal
            $stmt = $this->db->prepare("
                UPDATE product_images 
                SET is_primary = 1 
                WHERE id = ? AND product_id = ?
            ");
            return $stmt->execute([$imageId, $productId]);
        } catch (\PDOException $e) {
            error_log("❌ Error al establecer imagen principal: " . $e->getMessage());
            return false;
        }
    }

    // Métodos temporales para funcionalidades futuras
    public function getFavoriteProducts(int $userId): array
    {
        // Implementar cuando se cree la tabla product_favorites
        return [];
    }

    public function toggleFavorite(int $productId, int $userId): bool
    {
        // Implementar cuando se cree la tabla product_favorites
        return false;
    }

    public function getProductReviews(int $productId, ?int $userId = null): array
    {
        // Implementar cuando se cree la tabla product_reviews
        return [];
    }

    public function addProductReview(int $productId, array $reviewData, int $userId): ?int
    {
        // Implementar cuando se cree la tabla product_reviews
        return null;
    }

    public function updateProductReview(int $productId, int $reviewId, array $reviewData, int $userId): bool
    {
        // Implementar cuando se cree la tabla product_reviews
        return false;
    }

    public function deleteProductReview(int $productId, int $reviewId, int $userId): bool
    {
        // Implementar cuando se cree la tabla product_reviews
        return false;
    }

    public function getOwnerStats(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_products,
                SUM(CASE WHEN is_approved = 1 THEN 1 ELSE 0 END) as approved_products,
                SUM(CASE WHEN is_approved = 0 THEN 1 ELSE 0 END) as pending_products,
                SUM(stock_quantity) as total_stock,
                AVG(price) as average_price,
                SUM(views_count) as total_views
            FROM products 
            WHERE seller_id = ? AND is_active = 1
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function getPendingApproval(): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                p.*,
                c.name as category_name,
                co.name as country_name,
                u.name as seller_name,
                u.email as seller_email
            FROM products p
            JOIN categories c ON p.category_id = c.id
            JOIN countries co ON p.country_id = co.id
            JOIN users u ON p.seller_id = u.id
            WHERE p.is_approved = 0 AND p.is_active = 1
            ORDER BY p.created_at DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateApprovalStatus(int $productId, bool $isApproved, ?string $rejectionReason = null): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE products 
                SET is_approved = ?, rejection_reason = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            return $stmt->execute([$isApproved ? 1 : 0, $rejectionReason, $productId]);
        } catch (\PDOException $e) {
            error_log("❌ Error al actualizar estado de aprobación: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Generar SKU automático
     */
    private function generateSku(string $productName): string
    {
        $prefix = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $productName), 0, 3));
        $random = mt_rand(1000, 9999);
        return $prefix . $random;
    }

    /**
     * Verificar si el usuario es admin
     */
    private function isAdmin(?int $userId): bool
    {
        if (!$userId) return false;
        
        // Verificar si el usuario tiene rol de admin
        $stmt = $this->db->prepare("SELECT rol FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $user && $user['rol'] === 'ADMIN';
    }
}