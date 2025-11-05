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
                u.first_name as seller_name,
                u.rating as seller_rating
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
    public function getProduct(int $productId, ?int $userId = null): ?array
    {
        $sql = "
            SELECT 
                p.*,
                c.name as category_name,
                co.name as country_name,
                co.code as country_code,
                co.currency,
                u.first_name as seller_name,
                u.last_name as seller_last_name,
                u.rating as seller_rating,
                u.total_sales as seller_total_sales,
                COUNT(DISTINCT pi.id) as image_count,
                AVG(pr.rating) as average_rating,
                COUNT(pr.id) as review_count
            FROM products p
            JOIN categories c ON p.category_id = c.id
            JOIN countries co ON p.country_id = co.id
            JOIN users u ON p.seller_id = u.id
            LEFT JOIN product_images pi ON p.id = pi.product_id AND pi.is_primary = 1
            LEFT JOIN product_reviews pr ON p.id = pr.product_id
            WHERE p.id = ? AND p.is_active = 1
        ";

        // Si no es admin, solo mostrar productos aprobados
        if (!$this->isAdmin($userId)) {
            $sql .= " AND p.is_approved = 1";
        }

        $sql .= " GROUP BY p.id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$productId]);

        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        return $product ?: null;
    }

    /**
     * Obtener productos con filtros
     */

    public function getProducts(array $filters = [], ?int $userId = null): array
    {
        $whereConditions = ["p.is_active = 1"];
        $params = [];
        $types = []; // Para especificar tipos de parámetros

        // Solo mostrar productos aprobados para usuarios no admin
        if (!$this->isAdmin($userId)) {
            $whereConditions[] = "p.is_approved = 1";
        }

        // Aplicar filtros
        if (!empty($filters['category_id'])) {
            $whereConditions[] = "p.category_id = ?";
            $params[] = $filters['category_id'];
            $types[] = PDO::PARAM_INT;
        }

        if (!empty($filters['country_id'])) {
            $whereConditions[] = "p.country_id = ?";
            $params[] = $filters['country_id'];
            $types[] = PDO::PARAM_INT;
        }

        if (!empty($filters['category_name'])) {
            $whereConditions[] = "c.name = ?";
            $params[] = $filters['category_name'];
            $types[] = PDO::PARAM_STR;
        }

        if (!empty($filters['country_name'])) {
            $whereConditions[] = "co.name = ?";
            $params[] = $filters['country_name'];
            $types[] = PDO::PARAM_STR;
        }

        if (!empty($filters['min_price'])) {
            $whereConditions[] = "p.price >= ?";
            $params[] = $filters['min_price'];
            $types[] = PDO::PARAM_STR; // DECIMAL se maneja como string
        }

        if (!empty($filters['max_price'])) {
            $whereConditions[] = "p.price <= ?";
            $params[] = $filters['max_price'];
            $types[] = PDO::PARAM_STR;
        }

        if (!empty($filters['search'])) {
            $whereConditions[] = "(p.name LIKE ? OR p.description LIKE ?)";
            $searchTerm = "%{$filters['search']}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $types[] = PDO::PARAM_STR;
            $types[] = PDO::PARAM_STR;
        }

        if (!empty($filters['location'])) {
            $whereConditions[] = "u.city LIKE ?";
            $params[] = "%{$filters['location']}%";
            $types[] = PDO::PARAM_STR;
        }

        $sql = "
        SELECT 
            p.*,
            c.name as category_name,
            co.name as country_name,
            co.code as country_code,
            u.first_name as seller_name,
            u.rating as seller_rating,
            AVG(pr.rating) as average_rating,
            COUNT(pr.id) as review_count
        FROM products p
        JOIN categories c ON p.category_id = c.id
        JOIN countries co ON p.country_id = co.id
        JOIN users u ON p.seller_id = u.id
        LEFT JOIN product_reviews pr ON p.id = pr.product_id
    ";

        if (!empty($whereConditions)) {
            $sql .= " WHERE " . implode(" AND ", $whereConditions);
        }

        $sql .= " GROUP BY p.id ORDER BY p.created_at DESC";

        // Aplicar límite y offset - SIN PARÁMETROS, directamente en el SQL
        if (!empty($filters['limit'])) {
            $limit = (int)$filters['limit'];
            $sql .= " LIMIT $limit";
        }

        if (!empty($filters['offset'])) {
            $offset = (int)$filters['offset'];
            $sql .= " OFFSET $offset";
        }

        $stmt = $this->db->prepare($sql);

        // Bind parameters con tipos específicos si existen
        if (!empty($types)) {
            foreach ($params as $index => $value) {
                $stmt->bindValue($index + 1, $value, $types[$index]);
            }
            $stmt->execute();
        } else {
            $stmt->execute($params);
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Contar productos con filtros
     */

    public function getProductsCount(array $filters = []): int
    {
        $whereConditions = ["p.is_active = 1", "p.is_approved = 1"];
        $params = [];
        $types = [];

        // Aplicar filtros (misma lógica que getProducts)
        if (!empty($filters['category_id'])) {
            $whereConditions[] = "p.category_id = ?";
            $params[] = $filters['category_id'];
            $types[] = PDO::PARAM_INT;
        }

        if (!empty($filters['country_id'])) {
            $whereConditions[] = "p.country_id = ?";
            $params[] = $filters['country_id'];
            $types[] = PDO::PARAM_INT;
        }

        if (!empty($filters['category_name'])) {
            $whereConditions[] = "c.name = ?";
            $params[] = $filters['category_name'];
            $types[] = PDO::PARAM_STR;
        }

        if (!empty($filters['country_name'])) {
            $whereConditions[] = "co.name = ?";
            $params[] = $filters['country_name'];
            $types[] = PDO::PARAM_STR;
        }

        if (!empty($filters['min_price'])) {
            $whereConditions[] = "p.price >= ?";
            $params[] = $filters['min_price'];
            $types[] = PDO::PARAM_STR;
        }

        if (!empty($filters['max_price'])) {
            $whereConditions[] = "p.price <= ?";
            $params[] = $filters['max_price'];
            $types[] = PDO::PARAM_STR;
        }

        if (!empty($filters['search'])) {
            $whereConditions[] = "(p.name LIKE ? OR p.description LIKE ?)";
            $searchTerm = "%{$filters['search']}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $types[] = PDO::PARAM_STR;
            $types[] = PDO::PARAM_STR;
        }

        if (!empty($filters['location'])) {
            $whereConditions[] = "u.city LIKE ?";
            $params[] = "%{$filters['location']}%";
            $types[] = PDO::PARAM_STR;
        }

        $sql = "
        SELECT COUNT(DISTINCT p.id) as total 
        FROM products p
        JOIN categories c ON p.category_id = c.id
        JOIN countries co ON p.country_id = co.id
        JOIN users u ON p.seller_id = u.id
    ";

        if (!empty($whereConditions)) {
            $sql .= " WHERE " . implode(" AND ", $whereConditions);
        }

        $stmt = $this->db->prepare($sql);

        // Bind parameters con tipos específicos
        if (!empty($types)) {
            foreach ($params as $index => $value) {
                $stmt->bindValue($index + 1, $value, $types[$index]);
            }
            $stmt->execute();
        } else {
            $stmt->execute($params);
        }

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
        try {
            $fields = [];
            $params = [':id' => $productId, ':user_id' => $userId];

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
                if (array_key_exists($field, $data)) {
                    $fields[] = "$field = :$field";
                    $params[":$field"] = $data[$field];
                }
            }

            if (empty($fields)) {
                return false;
            }

            $sql = "UPDATE products SET " . implode(', ', $fields) . " 
                    WHERE id = :id AND seller_id = :user_id";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute($params);
        } catch (\PDOException $e) {
            error_log("❌ Error al actualizar producto: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Actualización parcial de producto
     */
    public function partialUpdateProduct(int $productId, array $data, int $userId): bool
    {
        return $this->updateProduct($productId, $data, $userId);
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

    /**
     * Obtener productos por categoría
     */
    public function getProductsByCategory(int $categoryId, ?int $userId = null): array
    {
        $filters = ['category_id' => $categoryId];
        return $this->getProducts($filters, $userId);
    }

    /**
     * Obtener productos por nombre de categoría
     */
    public function getProductsByCategoryName(string $categoryName, ?int $userId = null): array
    {
        $filters = ['category_name' => $categoryName];
        return $this->getProducts($filters, $userId);
    }

    /**
     * Obtener productos por país
     */
    public function getProductsByCountry(int $countryId, ?int $userId = null): array
    {
        $filters = ['country_id' => $countryId];
        return $this->getProducts($filters, $userId);
    }

    /**
     * Obtener productos por nombre de país
     */
    public function getProductsByCountryName(string $countryName, ?int $userId = null): array
    {
        $filters = ['country_name' => $countryName];
        return $this->getProducts($filters, $userId);
    }

    /**
     * Buscar productos
     */
    public function searchProducts(string $query, ?int $userId = null): array
    {
        $filters = ['search' => $query];
        return $this->getProducts($filters, $userId);
    }

    /**
     * Búsqueda avanzada
     */
    public function advancedSearchProducts(array $filters, ?int $userId = null): array
    {
        return $this->getProducts($filters, $userId);
    }

    /**
     * Contar resultados de búsqueda avanzada
     */
    public function getAdvancedSearchCount(array $filters): int
    {
        return $this->getProductsCount($filters);
    }

    /**
     * Obtener productos por ubicación
     */
    public function getProductsByLocation(string $city, ?int $userId = null): array
    {
        $filters = ['location' => $city];
        return $this->getProducts($filters, $userId);
    }

    /**
     * Actualizar imagen del producto
     */
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

    /**
     * Agregar imagen adicional al producto
     */
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
                ':is_primary' => 0 // No es la imagen principal por defecto
            ]);

            return $success ? $this->db->lastInsertId() : null;
        } catch (\PDOException $e) {
            error_log("❌ Error al agregar imagen del producto: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Eliminar imagen del producto
     */
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

    /**
     * Establecer imagen como principal
     */
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

    /**
     * Obtener productos favoritos del usuario
     */
    public function getFavoriteProducts(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                p.*,
                c.name as category_name,
                co.name as country_name,
                u.first_name as seller_name
            FROM product_favorites pf
            JOIN products p ON pf.product_id = p.id
            JOIN categories c ON p.category_id = c.id
            JOIN countries co ON p.country_id = co.id
            JOIN users u ON p.seller_id = u.id
            WHERE pf.user_id = ? AND p.is_active = 1 AND p.is_approved = 1
            ORDER BY pf.created_at DESC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Alternar favorito
     */
    public function toggleFavorite(int $productId, int $userId): bool
    {
        try {
            // Verificar si ya es favorito
            $stmt = $this->db->prepare("
                SELECT id FROM product_favorites 
                WHERE product_id = ? AND user_id = ?
            ");
            $stmt->execute([$productId, $userId]);
            $existing = $stmt->fetch();

            if ($existing) {
                // Eliminar de favoritos
                $stmt = $this->db->prepare("
                    DELETE FROM product_favorites 
                    WHERE product_id = ? AND user_id = ?
                ");
                return $stmt->execute([$productId, $userId]);
            } else {
                // Agregar a favoritos
                $stmt = $this->db->prepare("
                    INSERT INTO product_favorites (product_id, user_id) 
                    VALUES (?, ?)
                ");
                return $stmt->execute([$productId, $userId]);
            }
        } catch (\PDOException $e) {
            error_log("❌ Error al alternar favorito: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Actualizar stock
     */
    public function updateStock(int $productId, int $stockQuantity, int $userId): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE products 
                SET stock_quantity = ? 
                WHERE id = ? AND seller_id = ?
            ");
            return $stmt->execute([$stockQuantity, $productId, $userId]);
        } catch (\PDOException $e) {
            error_log("❌ Error al actualizar stock: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtener productos más vendidos
     */
    public function getBestSellers(?int $userId = null): array
    {
        $filters = ['limit' => 10];
        $products = $this->getProducts($filters, $userId);

        // Ordenar por ventas (aquí necesitarías una tabla de ventas/orders)
        usort($products, function ($a, $b) {
            return ($b['total_sales'] ?? 0) - ($a['total_sales'] ?? 0);
        });

        return array_slice($products, 0, 10);
    }

    /**
     * Obtener productos nuevos
     */
    public function getNewArrivals(?int $userId = null): array
    {
        $filters = ['limit' => 10];
        return $this->getProducts($filters, $userId);
    }

    /**
     * Obtener productos en oferta
     */
    public function getProductsOnSale(?int $userId = null): array
    {
        // Aquí podrías agregar lógica para productos con descuento
        $filters = ['limit' => 10];
        return $this->getProducts($filters, $userId);
    }

    /**
     * Obtener reviews de un producto
     */
    public function getProductReviews(int $productId, ?int $userId = null): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                pr.*,
                u.first_name,
                u.last_name,
                u.avatar_url
            FROM product_reviews pr
            JOIN users u ON pr.user_id = u.id
            WHERE pr.product_id = ?
            ORDER BY pr.created_at DESC
        ");
        $stmt->execute([$productId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Agregar review a un producto
     */
    public function addProductReview(int $productId, array $reviewData, int $userId): ?int
    {
        try {
            $sql = "INSERT INTO product_reviews 
                    (product_id, user_id, rating, comment) 
                    VALUES (:product_id, :user_id, :rating, :comment)";

            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                ':product_id' => $productId,
                ':user_id' => $userId,
                ':rating' => $reviewData['rating'],
                ':comment' => $reviewData['comment'] ?? null
            ]);

            return $success ? $this->db->lastInsertId() : null;
        } catch (\PDOException $e) {
            error_log("❌ Error al agregar review: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Actualizar review
     */
    public function updateProductReview(int $productId, int $reviewId, array $reviewData, int $userId): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE product_reviews 
                SET rating = ?, comment = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND product_id = ? AND user_id = ?
            ");
            return $stmt->execute([
                $reviewData['rating'],
                $reviewData['comment'] ?? null,
                $reviewId,
                $productId,
                $userId
            ]);
        } catch (\PDOException $e) {
            error_log("❌ Error al actualizar review: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Eliminar review
     */
    public function deleteProductReview(int $productId, int $reviewId, int $userId): bool
    {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM product_reviews 
                WHERE id = ? AND product_id = ? AND user_id = ?
            ");
            return $stmt->execute([$reviewId, $productId, $userId]);
        } catch (\PDOException $e) {
            error_log("❌ Error al eliminar review: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtener estadísticas del vendedor
     */
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

    /**
     * Obtener productos pendientes de aprobación
     */
    public function getPendingApproval(): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                p.*,
                c.name as category_name,
                co.name as country_name,
                u.first_name as seller_name,
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

    /**
     * Actualizar estado de aprobación
     */
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

        // Implementar lógica para verificar si es admin
        // Por ahora retornamos false por seguridad
        return false;
    }
}
