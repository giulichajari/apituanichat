-- Permitir mensajes de tipo 'audio' en la tabla mensajes
-- Ejecutar en MySQL: mysql -u usuario -p base_datos < migrations/add_tipo_audio_mensajes.sql
--
-- Si la columna tipo es ENUM, añadir el valor 'audio':
-- ALTER TABLE mensajes MODIFY COLUMN tipo ENUM('texto','imagen','archivo','audio') NOT NULL DEFAULT 'texto';
--
-- Si prefieres aceptar cualquier tipo futuro sin cambiar el ENUM cada vez, usa VARCHAR:
ALTER TABLE mensajes MODIFY COLUMN tipo VARCHAR(20) NOT NULL DEFAULT 'texto';
