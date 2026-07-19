<?php

namespace App\Models;

use App\Configs\Database;
use PDO;

class CountryRateModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function getAllActive(): array
    {
        $stmt = $this->db->prepare("
            SELECT
                cr.id,
                cr.country_id,
                c.name AS country_name,
                c.code AS country_code,
                cr.code_alpha2,
                cr.pricing_model,
                cr.passenger_rate_min,
                cr.passenger_rate_max,
                cr.package_moto_rate,
                cr.package_auto_rate,
                cr.package_camioneta_rate,
                cr.package_carga_rate,
                cr.is_active,
                cr.updated_at
            FROM country_rates cr
            INNER JOIN countries c ON c.id = cr.country_id
            WHERE cr.is_active = 1 AND c.is_active = 1
            ORDER BY
                CASE WHEN cr.pricing_model = 'hourly' THEN 0 ELSE 1 END,
                c.name
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getByAlpha2(string $codeAlpha2): ?array
    {
        $stmt = $this->db->prepare("
            SELECT
                cr.id,
                cr.country_id,
                c.name AS country_name,
                c.code AS country_code,
                cr.code_alpha2,
                cr.pricing_model,
                cr.passenger_rate_min,
                cr.passenger_rate_max,
                cr.package_moto_rate,
                cr.package_auto_rate,
                cr.package_camioneta_rate,
                cr.package_carga_rate,
                cr.is_active,
                cr.updated_at
            FROM country_rates cr
            INNER JOIN countries c ON c.id = cr.country_id
            WHERE cr.code_alpha2 = ? AND cr.is_active = 1 AND c.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([strtoupper($codeAlpha2)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT
                cr.id,
                cr.country_id,
                c.name AS country_name,
                c.code AS country_code,
                cr.code_alpha2,
                cr.pricing_model,
                cr.passenger_rate_min,
                cr.passenger_rate_max,
                cr.package_moto_rate,
                cr.package_auto_rate,
                cr.package_camioneta_rate,
                cr.package_carga_rate,
                cr.is_active,
                cr.updated_at
            FROM country_rates cr
            INNER JOIN countries c ON c.id = cr.country_id
            WHERE cr.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function update(int $id, array $data): bool
    {
        try {
            $allowed = [
                'passenger_rate_min',
                'passenger_rate_max',
                'package_moto_rate',
                'package_auto_rate',
                'package_camioneta_rate',
                'package_carga_rate',
                'pricing_model',
                'is_active',
            ];

            $fields = [];
            $params = [':id' => $id];

            foreach ($allowed as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "$field = :$field";
                    $params[":$field"] = $data[$field];
                }
            }

            if (empty($fields)) {
                return false;
            }

            $sql = 'UPDATE country_rates SET ' . implode(', ', $fields) . ' WHERE id = :id';
            $stmt = $this->db->prepare($sql);
            return $stmt->execute($params);
        } catch (\PDOException $e) {
            error_log('CountryRateModel::update error: ' . $e->getMessage());
            return false;
        }
    }
}
