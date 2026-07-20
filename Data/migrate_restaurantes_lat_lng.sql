-- Coordenadas de comedores para el mapa de drivers / remis
-- Ejecutar una sola vez:
--   mysql -u USER -p DBNAME < Data/migrate_restaurantes_lat_lng.sql

ALTER TABLE restaurantes
  ADD COLUMN lat DECIMAL(10, 7) NULL AFTER ubicacion,
  ADD COLUMN lng DECIMAL(10, 7) NULL AFTER lat;
