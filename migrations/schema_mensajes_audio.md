# Registro en BD: mensajes de audio

Los mensajes de voz se guardan en el servidor usando las mismas tablas que imágenes y archivos.

## Tablas utilizadas

### `mensajes`
- **chat_id**, **user_id**, **contenido**, **tipo**, **file_id**, **enviado_en**, **leido**, etc.
- Para audio: `tipo = 'audio'` y `file_id` apunta al archivo en `files`.

### `files`
- **id**, **name**, **original_name**, **path**, **url**, **size**, **mime_type**, **chat_id**, **user_id**, **created_at**
- El archivo físico se guarda en `/uploads/chats/{chat_id}/` (ej. `audio_123.webm`).

## Flujo

1. El front sube el audio con `POST /chats/:chat_id/upload` (FormData con `file`, `chat_id`, `other_user_id`, `user_id`, `tipo=audio`).
2. El backend (FileUploadService) guarda el archivo en disco, inserta una fila en `files` y otra en `mensajes` con `tipo = 'audio'` y el `file_id` correspondiente.
3. Al listar mensajes (`GET /chats/:chat_id/messages`), cada mensaje de audio viene con los campos de `files` (file_url, file_original_name, file_mime_type, etc.) gracias al `LEFT JOIN files f ON m.file_id = f.id`.

No hace falta crear tablas nuevas: `files` ya almacena cualquier archivo del chat.

### Migración necesaria para `tipo = 'audio'`

Si aparece **"Data truncated for column 'tipo' at row 1"**, la columna `mensajes.tipo` es un ENUM que no incluye `'audio'` o un VARCHAR demasiado corto. Ejecuta:

```sql
-- Opción recomendada: aceptar cualquier tipo (texto, imagen, archivo, audio, etc.)
ALTER TABLE mensajes MODIFY COLUMN tipo VARCHAR(20) NOT NULL DEFAULT 'texto';
```

O si quieres mantener ENUM y solo añadir audio:

```sql
ALTER TABLE mensajes MODIFY COLUMN tipo ENUM('texto','imagen','archivo','audio') NOT NULL DEFAULT 'texto';
```

Script listo en: `migrations/add_tipo_audio_mensajes.sql`.
