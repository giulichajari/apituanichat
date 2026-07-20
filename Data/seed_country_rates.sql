-- Seed tarifas Remis (USD/km) — ejecutar en la BD del proyecto (ej. tuanichatbd)
-- Requiere que exista la tabla `countries` con códigos tipo ARG, USA, MEX, etc.
-- Si falta countries: primero ejecutar seed_countries.sql

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `country_rates`
  (`country_id`, `code_alpha2`, `pricing_model`, `passenger_rate_min`, `passenger_rate_max`,
   `package_moto_rate`, `package_auto_rate`, `package_camioneta_rate`, `package_carga_rate`)
SELECT c.id, v.code_alpha2, 'per_km', v.rate_min, v.rate_max, NULL, NULL, NULL, NULL
FROM (
  SELECT 'USA' AS code, 'US' AS code_alpha2, 1.0000 AS rate_min, 1.0000 AS rate_max
  UNION ALL SELECT 'MEX', 'MX', 0.2000, 0.2000
  UNION ALL SELECT 'BOL', 'BO', 0.2600, 0.3600
  UNION ALL SELECT 'PRY', 'PY', 0.2700, 0.4000
  UNION ALL SELECT 'ARG', 'AR', 0.2900, 0.2900
  UNION ALL SELECT 'BRA', 'BR', 0.2800, 0.4400
  UNION ALL SELECT 'PER', 'PE', 0.3200, 0.4800
  UNION ALL SELECT 'COL', 'CO', 0.3300, 0.4500
  UNION ALL SELECT 'CHL', 'CL', 0.3300, 0.5500
  UNION ALL SELECT 'NIC', 'NI', 0.3500, 0.5500
  UNION ALL SELECT 'SLV', 'SV', 0.4000, 0.6000
  UNION ALL SELECT 'ECU', 'EC', 0.4000, 0.6000
  UNION ALL SELECT 'HND', 'HN', 0.4000, 0.5500
  UNION ALL SELECT 'CRI', 'CR', 0.4500, 0.6000
  UNION ALL SELECT 'PAN', 'PA', 0.5000, 0.7000
  UNION ALL SELECT 'VEN', 'VE', 0.5000, 0.8000
  UNION ALL SELECT 'URY', 'UY', 0.6500, 0.9000
) AS v
INNER JOIN `countries` c ON (
  UPPER(TRIM(c.code)) = v.code
  OR UPPER(TRIM(c.code)) = v.code_alpha2
)
ON DUPLICATE KEY UPDATE
  `code_alpha2` = VALUES(`code_alpha2`),
  `pricing_model` = 'per_km',
  `passenger_rate_min` = VALUES(`passenger_rate_min`),
  `passenger_rate_max` = VALUES(`passenger_rate_max`),
  `is_active` = 1;

-- Verificación
SELECT cr.id, c.name, c.code, cr.code_alpha2, cr.passenger_rate_min, cr.passenger_rate_max
FROM country_rates cr
JOIN countries c ON c.id = cr.country_id
ORDER BY c.name;
