<?php

namespace App\Models;

use App\Configs\Database;
use PDO;

class CategoryModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Obtener todas las categorías
     */
    public function getAllCategories(): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM categories 
            WHERE is_active = 1 
            ORDER BY name
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtener categoría por ID
     */
    public function getCategory(int $categoryId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM categories 
            WHERE id = ? AND is_active = 1
        ");
        $stmt->execute([$categoryId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Obtener categoría por nombre
     */
    public function getCategoryByName(string $categoryName): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM categories 
            WHERE name = ? AND is_active = 1
        ");
        $stmt->execute([$categoryName]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Crear nueva categoría
     */
    public function createCategory(array $data): ?int
    {
        try {
            $sql = "INSERT INTO categories (name, description, icon) 
                    VALUES (:name, :description, :icon)";
            
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                ':name' => $data['name'],
                ':description' => $data['description'] ?? null,
                ':icon' => $data['icon'] ?? null
            ]);

            return $success ? $this->db->lastInsertId() : null;
        } catch (\PDOException $e) {
            error_log("❌ Error al crear categoría: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Actualizar categoría
     */
    public function updateCategory(int $categoryId, array $data): bool
    {
        try {
            $fields = [];
            $params = [':id' => $categoryId];

            $allowedFields = ['name', 'description', 'icon', 'is_active'];

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "$field = :$field";
                    $params[":$field"] = $data[$field];
                }
            }

            if (empty($fields)) {
                return false;
            }

            $sql = "UPDATE categories SET " . implode(', ', $fields) . " 
                    WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute($params);
        } catch (\PDOException $e) {
            error_log("❌ Error al actualizar categoría: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Eliminar categoría (borrado lógico)
     */
    public function deleteCategory(int $categoryId): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE categories 
                SET is_active = 0 
                WHERE id = ?
            ");
            return $stmt->execute([$categoryId]);
        } catch (\PDOException $e) {
            error_log("❌ Error al eliminar categoría: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtener estadísticas de categorías
     */
    public function getCategoryStats(): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                c.id,
                c.name,
                c.icon,
                COUNT(p.id) as product_count,
                AVG(p.price) as average_price,
                MIN(p.price) as min_price,
                MAX(p.price) as max_price
            FROM categories c
            LEFT JOIN products p ON c.id = p.category_id AND p.is_active = 1 AND p.is_approved = 1
            WHERE c.is_active = 1
            GROUP BY c.id, c.name, c.icon
            ORDER BY product_count DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtener categorías populares (con más productos)
     */
    public function getPopularCategories(int $limit = 10): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                c.id,
                c.name,
                c.icon,
                COUNT(p.id) as product_count
            FROM categories c
            LEFT JOIN products p ON c.id = p.category_id AND p.is_active = 1 AND p.is_approved = 1
            WHERE c.is_active = 1
            GROUP BY c.id, c.name, c.icon
            ORDER BY product_count DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}