-- USA: cobro en USD/hora (duración del viaje × tarifa horaria).
-- Ejecutar una sola vez. Re-seed también actualiza vía CountryRateModel::seedDefaults.

UPDATE `country_rates` cr
INNER JOIN `countries` c ON c.id = cr.country_id
  AND (
    UPPER(TRIM(c.code)) IN ('USA', 'US')
    OR UPPER(TRIM(COALESCE(cr.code_alpha2, ''))) = 'US'
  )
SET
  cr.pricing_model = 'hourly',
  cr.passenger_rate_min = CASE
    WHEN cr.passenger_rate_min IS NULL OR cr.passenger_rate_min <= 5
      THEN 35.0000
    ELSE cr.passenger_rate_min
  END,
  cr.passenger_rate_max = CASE
    WHEN cr.passenger_rate_max IS NULL OR cr.passenger_rate_max <= 5
      THEN 35.0000
    ELSE cr.passenger_rate_max
  END;
