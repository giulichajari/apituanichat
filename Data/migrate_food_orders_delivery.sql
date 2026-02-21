-- Opción delivery en pedidos Tuani Eats: dirección y teléfono para envío.
-- Ejecutar una sola vez. Si las columnas ya existen, ignorar el error.
ALTER TABLE food_orders ADD COLUMN is_delivery TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE food_orders ADD COLUMN delivery_address VARCHAR(500) NULL;
ALTER TABLE food_orders ADD COLUMN delivery_phone VARCHAR(50) NULL;
