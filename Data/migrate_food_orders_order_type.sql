-- Tipos de pedido Tuani Eats: delivery | dine_in | reservation
-- Ejecutar una sola vez. Si las columnas ya existen, ignorar el error.
ALTER TABLE food_orders
  ADD COLUMN order_type ENUM('delivery', 'dine_in', 'reservation') NOT NULL DEFAULT 'dine_in';

ALTER TABLE food_orders
  ADD COLUMN reservation_at DATETIME NULL;

-- Sincronizar pedidos existentes que ya tenían delivery
UPDATE food_orders SET order_type = 'delivery' WHERE is_delivery = 1;
