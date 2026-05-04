-- websocket_notifications.status: el PHP usa 'pending', 'processed', 'error'
-- (ws-server.php markAsProcessed / ChatModel createWebSocketNotification).
-- Error 1265 "Data truncated for column 'status'" = ENUM/tipo no admite esos valores.
--
-- 1) Ver definición actual:
--    SHOW COLUMNS FROM websocket_notifications LIKE 'status';
--
-- 2) Opción recomendada: VARCHAR (flexible, sin truncar al añadir estados)
ALTER TABLE websocket_notifications
  MODIFY COLUMN status VARCHAR(32) NOT NULL DEFAULT 'pending';

-- 3) Alternativa si quieres seguir con ENUM:
-- ALTER TABLE websocket_notifications
--   MODIFY COLUMN status ENUM('pending','processed','error') NOT NULL DEFAULT 'pending';
