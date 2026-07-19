-- Tarifas Remis por país (todos: USD/km)
-- Criterio de rango: si min ≠ max, se usa el punto medio como tarifa económica.
-- Clases superiores (pasajeros y paquetes): +42% sobre la clase anterior.

CREATE TABLE IF NOT EXISTS `country_rates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `country_id` int(11) NOT NULL,
  `code_alpha2` varchar(2) NOT NULL COMMENT 'ISO 3166-1 alpha-2 para geolocalización',
  `pricing_model` enum('hourly','per_km') NOT NULL DEFAULT 'per_km',
  `passenger_rate_min` decimal(10,4) NOT NULL COMMENT 'Clase económica (mínimo del rango) USD/km',
  `passenger_rate_max` decimal(10,4) NOT NULL COMMENT 'Clase económica (máximo del rango; igual a min si es fijo)',
  `package_moto_rate` decimal(10,4) DEFAULT NULL COMMENT 'Reservado / legacy; el cálculo usa +42% desde económica',
  `package_auto_rate` decimal(10,4) DEFAULT NULL,
  `package_camioneta_rate` decimal(10,4) DEFAULT NULL,
  `package_carga_rate` decimal(10,4) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_country_rates_country` (`country_id`),
  UNIQUE KEY `uq_country_rates_alpha2` (`code_alpha2`),
  CONSTRAINT `fk_country_rates_country` FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed USA + LATAM (todos per_km). USA económica: 1.00 USD/km (editable en admin).
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
INNER JOIN `countries` c ON c.code = v.code
ON DUPLICATE KEY UPDATE
  `code_alpha2` = VALUES(`code_alpha2`),
  `pricing_model` = VALUES(`pricing_model`),
  `passenger_rate_min` = VALUES(`passenger_rate_min`),
  `passenger_rate_max` = VALUES(`passenger_rate_max`),
  `package_moto_rate` = NULL,
  `package_auto_rate` = NULL,
  `package_camioneta_rate` = NULL,
  `package_carga_rate` = NULL,
  `is_active` = 1;

-- Si USA quedó como hourly de un seed anterior, forzar per_km
UPDATE `country_rates` cr
INNER JOIN `countries` c ON c.id = cr.country_id AND c.code = 'USA'
SET
  cr.passenger_rate_min = IF(cr.pricing_model = 'hourly', 1.0000, cr.passenger_rate_min),
  cr.passenger_rate_max = IF(cr.pricing_model = 'hourly', 1.0000, cr.passenger_rate_max),
  cr.pricing_model = 'per_km',
  cr.package_moto_rate = NULL,
  cr.package_auto_rate = NULL,
  cr.package_camioneta_rate = NULL,
  cr.package_carga_rate = NULL;
