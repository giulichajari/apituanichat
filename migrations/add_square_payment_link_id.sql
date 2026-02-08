-- Añadir columna para vincular webhook de Square con nuestros pedidos
-- Ejecutar en MySQL: mysql -u usuario -p base_datos < migrations/add_square_payment_link_id.sql
ALTER TABLE food_orders ADD COLUMN square_payment_link_id VARCHAR(100) NULL AFTER payment_link_url;
