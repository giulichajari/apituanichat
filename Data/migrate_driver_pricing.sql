-- Precios pasajero/paquete por banda de peso + datos de paquete en solicitudes
ALTER TABLE drivers
  ADD COLUMN precio_paquete_hasta_1kg DECIMAL(10,2) DEFAULT 0 COMMENT 'Precio por km paquetes hasta 1 kg',
  ADD COLUMN precio_paquete_1_5kg DECIMAL(10,2) DEFAULT 0 COMMENT 'Precio por km paquetes 1-5 kg',
  ADD COLUMN precio_paquete_5_10kg DECIMAL(10,2) DEFAULT 0 COMMENT 'Precio por km paquetes 5-10 kg';

ALTER TABLE ride_requests
  ADD COLUMN service_type VARCHAR(20) DEFAULT 'passenger',
  ADD COLUMN package_weight_kg DECIMAL(10,2) NULL,
  ADD COLUMN package_length_cm DECIMAL(10,2) NULL,
  ADD COLUMN package_width_cm DECIMAL(10,2) NULL,
  ADD COLUMN package_height_cm DECIMAL(10,2) NULL,
  ADD COLUMN package_type VARCHAR(50) NULL,
  ADD COLUMN package_details JSON NULL;
