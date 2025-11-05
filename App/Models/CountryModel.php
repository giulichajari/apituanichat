<?php

namespace App\Models;

use App\Configs\Database;
use PDO;

class CountryModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Obtener todos los países
     */
    public function getAllCountries(): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM countries 
            WHERE is_active = 1 
            ORDER BY name
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtener país por ID
     */
    public function getCountry(int $countryId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM countries 
            WHERE id = ? AND is_active = 1
        ");
        $stmt->execute([$countryId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Obtener país por código
     */
    public function getCountryByCode(string $countryCode): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM countries 
            WHERE code = ? AND is_active = 1
        ");
        $stmt->execute([$countryCode]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Obtener país por nombre
     */
    public function getCountryByName(string $countryName): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM countries 
            WHERE name = ? AND is_active = 1
        ");
        $stmt->execute([$countryName]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Crear nuevo país
     */
    public function createCountry(array $data): ?int
    {
        try {
            $sql = "INSERT INTO countries (name, code, currency) 
                    VALUES (:name, :code, :currency)";
            
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                ':name' => $data['name'],
                ':code' => $data['code'],
                ':currency' => $data['currency'] ?? 'USD'
            ]);

            return $success ? $this->db->lastInsertId() : null;
        } catch (\PDOException $e) {
            error_log("❌ Error al crear país: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Actualizar país
     */
    public function updateCountry(int $countryId, array $data): bool
    {
        try {
            $fields = [];
            $params = [':id' => $countryId];

            $allowedFields = ['name', 'code', 'currency', 'is_active'];

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "$field = :$field";
                    $params[":$field"] = $data[$field];
                }
            }

            if (empty($fields)) {
                return false;
            }

            $sql = "UPDATE countries SET " . implode(', ', $fields) . " 
                    WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute($params);
        } catch (\PDOException $e) {
            error_log("❌ Error al actualizar país: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Eliminar país (borrado lógico)
     */
    public function deleteCountry(int $countryId): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE countries 
                SET is_active = 0 
                WHERE id = ?
            ");
            return $stmt->execute([$countryId]);
        } catch (\PDOException $e) {
            error_log("❌ Error al eliminar país: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtener estadísticas de países
     */
    public function getCountryStats(): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                co.id,
                co.name,
                co.code,
                co.currency,
                COUNT(p.id) as product_count,
                COUNT(DISTINCT p.seller_id) as seller_count,
                AVG(p.price) as average_price
            FROM countries co
            LEFT JOIN products p ON co.id = p.country_id AND p.is_active = 1 AND p.is_approved = 1
            WHERE co.is_active = 1
            GROUP BY co.id, co.name, co.code, co.currency
            ORDER BY product_count DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtener países con más productos
     */
    public function getTopCountries(int $limit = 10): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                co.id,
                co.name,
                co.code,
                COUNT(p.id) as product_count
            FROM countries co
            LEFT JOIN products p ON co.id = p.country_id AND p.is_active = 1 AND p.is_approved = 1
            WHERE co.is_active = 1
            GROUP BY co.id, co.name, co.code
            ORDER BY product_count DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}