-- Pedidos de comida como invitado (deep link /restaurante sin registro)
-- Ejecutar una sola vez:
--   mysql -u USER -p DBNAME < Data/migrate_food_orders_guest.sql

ALTER TABLE food_orders
  MODIFY COLUMN user_id INT(11) NULL;

ALTER TABLE food_orders
  ADD COLUMN guest_email VARCHAR(255) NULL AFTER user_id;
