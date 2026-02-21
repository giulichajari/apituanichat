-- Token FCM para notificaciones push (llamadas y mensajes) cuando la app está en segundo plano.
-- Ejecutar una sola vez. Si la columna ya existe, ignorar el error.
ALTER TABLE users ADD COLUMN fcm_token VARCHAR(512) NULL;
