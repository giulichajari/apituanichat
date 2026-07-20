<?php

namespace App\Models;

use App\Configs\Database;
use PDO;
use PDOException;

class CountryRateModel
{
    private PDO $db;
    private static bool $ensured = false;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Crea la tabla si no existe y siembra tarifas si está vacía.
     */
    public function ensureSchemaAndSeed(): void
    {
        if (self::$ensured) {
            return;
        }

        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS `country_rates` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `country_id` int(11) NOT NULL,
                  `code_alpha2` varchar(2) NOT NULL,
                  `pricing_model` enum('hourly','per_km') NOT NULL DEFAULT 'per_km',
                  `passenger_rate_min` decimal(10,4) NOT NULL,
                  `passenger_rate_max` decimal(10,4) NOT NULL,
                  `package_moto_rate` decimal(10,4) DEFAULT NULL,
                  `package_auto_rate` decimal(10,4) DEFAULT NULL,
                  `package_camioneta_rate` decimal(10,4) DEFAULT NULL,
                  `package_carga_rate` decimal(10,4) DEFAULT NULL,
                  `is_active` tinyint(1) NOT NULL DEFAULT 1,
                  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uq_country_rates_country` (`country_id`),
                  UNIQUE KEY `uq_country_rates_alpha2` (`code_alpha2`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $count = (int) $this->db->query('SELECT COUNT(*) FROM country_rates')->fetchColumn();
            if ($count === 0) {
                $this->seedDefaults();
            }

            self::$ensured = true;
        } catch (PDOException $e) {
            error_log('CountryRateModel::ensureSchemaAndSeed: ' . $e->getMessage());
            throw $e;
        }
    }

    public function seedDefaults(): int
    {
        $sql = "
            INSERT INTO `country_rates`
              (`country_id`, `code_alpha2`, `pricing_model`, `passenger_rate_min`, `passenger_rate_max`,
               `package_moto_rate`, `package_auto_rate`, `package_camioneta_rate`, `package_carga_rate`)
            SELECT c.id, v.code_alpha2, v.pricing_model, v.rate_min, v.rate_max,
                   NULL, NULL, NULL, NULL
            FROM (
              SELECT 'USA' AS code, 'US' AS code_alpha2, 'per_km' AS pricing_model, 1.0000 AS rate_min, 1.0000 AS rate_max
              UNION ALL SELECT 'MEX', 'MX', 'per_km', 0.2000, 0.2000
              UNION ALL SELECT 'BOL', 'BO', 'per_km', 0.2600, 0.3600
              UNION ALL SELECT 'PRY', 'PY', 'per_km', 0.2700, 0.4000
              UNION ALL SELECT 'ARG', 'AR', 'per_km', 0.2900, 0.2900
              UNION ALL SELECT 'BRA', 'BR', 'per_km', 0.2800, 0.4400
              UNION ALL SELECT 'PER', 'PE', 'per_km', 0.3200, 0.4800
              UNION ALL SELECT 'COL', 'CO', 'per_km', 0.3300, 0.4500
              UNION ALL SELECT 'CHL', 'CL', 'per_km', 0.3300, 0.5500
              UNION ALL SELECT 'NIC', 'NI', 'per_km', 0.3500, 0.5500
              UNION ALL SELECT 'SLV', 'SV', 'per_km', 0.4000, 0.6000
              UNION ALL SELECT 'ECU', 'EC', 'per_km', 0.4000, 0.6000
              UNION ALL SELECT 'HND', 'HN', 'per_km', 0.4000, 0.5500
              UNION ALL SELECT 'CRI', 'CR', 'per_km', 0.4500, 0.6000
              UNION ALL SELECT 'PAN', 'PA', 'per_km', 0.5000, 0.7000
              UNION ALL SELECT 'VEN', 'VE', 'per_km', 0.5000, 0.8000
              UNION ALL SELECT 'URY', 'UY', 'per_km', 0.6500, 0.9000
            ) AS v
            INNER JOIN `countries` c ON (
              UPPER(TRIM(c.code)) = v.code
              OR UPPER(TRIM(c.code)) = v.code_alpha2
            )
            ON DUPLICATE KEY UPDATE
              `code_alpha2` = VALUES(`code_alpha2`),
              `pricing_model` = VALUES(`pricing_model`),
              `passenger_rate_min` = VALUES(`passenger_rate_min`),
              `passenger_rate_max` = VALUES(`passenger_rate_max`),
              `is_active` = 1
        ";
        $this->db->exec($sql);
        return (int) $this->db->query('SELECT COUNT(*) FROM country_rates')->fetchColumn();
    }

    public function getAllActive(): array
    {
        $this->ensureSchemaAndSeed();

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
            WHERE cr.is_active = 1 AND (c.is_active = 1 OR c.is_active IS NULL)
            ORDER BY c.name
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getByAlpha2(string $codeAlpha2): ?array
    {
        $this->ensureSchemaAndSeed();

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
            WHERE cr.code_alpha2 = ? AND cr.is_active = 1 AND (c.is_active = 1 OR c.is_active IS NULL)
            LIMIT 1
        ");
        $stmt->execute([strtoupper($codeAlpha2)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getById(int $id): ?array
    {
        $this->ensureSchemaAndSeed();

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
            $this->ensureSchemaAndSeed();

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
        } catch (PDOException $e) {
            error_log('CountryRateModel::update error: ' . $e->getMessage());
            return false;
        }
    }
}
