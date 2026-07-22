-- Aprobación admin de puntos GPS de comedores (mapa Remis)
-- Ejecutar una sola vez:
--   mysql -u USER -p DBNAME < Data/migrate_restaurantes_location_approved.sql

ALTER TABLE restaurantes
  ADD COLUMN location_approved TINYINT(1) NOT NULL DEFAULT 0 AFTER lng;

-- Puntos ya cargados quedan pendientes hasta revisión del admin
UPDATE restaurantes
SET location_approved = 0
WHERE lat IS NOT NULL AND lng IS NOT NULL;
