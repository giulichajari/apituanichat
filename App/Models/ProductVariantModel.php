<?php

namespace App\Models;

use App\Configs\Database;
use PDO;
use PDOException;

class ProductVariantModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    // Dueno real del producto (seller_id), o false si el producto no existe.
    // Usado para chequear permisos antes de crear/editar/borrar variantes.
    public function getProductOwnerId(int $productId): int|false
    {
        $stmt = $this->db->prepare("SELECT seller_id FROM products WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $productId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int) $row['seller_id'] : false;
    }

    // Lista publica de variantes activas de un producto (para la ficha del producto / visor 3D)
    public function getVariantsByProductId(int $productId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM product_variants
            WHERE product_id = :product_id AND activo = 1
            ORDER BY id ASC
        ");
        $stmt->execute([':product_id' => $productId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Todas las variantes del producto (incluye inactivas), para el vendedor gestionando su propio producto
    public function getAllVariantsByProductId(int $productId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM product_variants
            WHERE product_id = :product_id
            ORDER BY id ASC
        ");
        $stmt->execute([':product_id' => $productId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getVariantById(int $id): array|false
    {
        $stmt = $this->db->prepare("SELECT * FROM product_variants WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: false;
    }

    public function createVariant(
        int $productId,
        ?string $color,
        ?string $talla,
        ?string $marca,
        ?float $precio,
        int $stockQuantity
    ): int|false {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO product_variants (product_id, color, talla, marca, precio, stock_quantity)
                VALUES (:product_id, :color, :talla, :marca, :precio, :stock_quantity)
            ");
            $ok = $stmt->execute([
                ':product_id' => $productId,
                ':color' => $color,
                ':talla' => $talla,
                ':marca' => $marca,
                ':precio' => $precio,
                ':stock_quantity' => $stockQuantity,
            ]);
            return $ok ? (int) $this->db->lastInsertId() : false;
        } catch (PDOException $e) {
            error_log("ProductVariantModel createVariant ERROR: " . $e->getMessage());
            return false;
        }
    }

    // Actualiza solo los campos presentes en $fields (color, talla, marca, precio, stock_quantity, activo)
    public function updateVariant(int $id, array $fields): bool
    {
        $allowed = ['color', 'talla', 'marca', 'precio', 'stock_quantity', 'activo'];
        $sets = [];
        $params = [':id' => $id];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $fields)) {
                $sets[] = "$field = :$field";
                $params[":$field"] = $fields[$field];
            }
        }

        if (empty($sets)) {
            return true; // nada que actualizar
        }

        try {
            $sql = "UPDATE product_variants SET " . implode(', ', $sets) . " WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("ProductVariantModel updateVariant ERROR: " . $e->getMessage());
            return false;
        }
    }

    // Guarda la URL del .glb subido para esta variante
    public function setGlbUrl(int $id, string $url): bool
    {
        try {
            $stmt = $this->db->prepare("UPDATE product_variants SET glb_url = :url WHERE id = :id");
            return $stmt->execute([':url' => $url, ':id' => $id]);
        } catch (PDOException $e) {
            error_log("ProductVariantModel setGlbUrl ERROR: " . $e->getMessage());
            return false;
        }
    }

    // Borrado logico (activo = 0), no borra el registro por si hay pedidos historicos que la referencian
    public function deactivateVariant(int $id): bool
    {
        try {
            $stmt = $this->db->prepare("UPDATE product_variants SET activo = 0 WHERE id = :id");
            return $stmt->execute([':id' => $id]);
        } catch (PDOException $e) {
            error_log("ProductVariantModel deactivateVariant ERROR: " . $e->getMessage());
            return false;
        }
    }
}
