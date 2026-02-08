# Webhook de Square - Tuani Eats

## Flujo de pago

1. **Cliente hace pedido** → Se crea orden con estado `pending`.
2. **Restaurante confirma** → Se crea link de pago Square, se envía email al comprador con el link.
3. **Comprador paga** → Square envía webhook a nuestra API.
4. **API procesa webhook** → Marca pedido como `paid`. El restaurante ve el pago en su panel.

## Configuración

### 1. Migración de base de datos

Ejecutar para añadir la columna `square_payment_link_id`:

```bash
mysql -u usuario -p nombre_bd < migrations/add_square_payment_link_id.sql
```

O en phpMyAdmin/MySQL Workbench ejecutar:

```sql
ALTER TABLE food_orders ADD COLUMN square_payment_link_id VARCHAR(100) NULL AFTER payment_link_url;
```

### 2. Variables de entorno (.env)

```
SQUARE_ACCESS_TOKEN=tu_token_sandbox_o_produccion
SQUARE_LOCATION_ID=tu_location_id
MAIL_FROM=noreply@tudominio.com
MAIL_REPLY=soporte@tudominio.com

# Opcional: para validar firma del webhook (recomendado en producción)
SQUARE_WEBHOOK_SIGNATURE_KEY=clave_del_dashboard_square
SQUARE_WEBHOOK_NOTIFICATION_URL=https://tudominio.com/webhooks/square
```

### 3. Configurar webhook en Square Developer Console

1. Ir a [Square Developer Console](https://developer.squareup.com/apps)
2. Seleccionar tu aplicación
3. Webhooks → Añadir URL de notificación: `https://tudominio.com/webhooks/square`
4. Suscribirse al evento: **payment.updated** (o payment.completed si está disponible)
5. Copiar la **Signature Key** y añadirla a `SQUARE_WEBHOOK_SIGNATURE_KEY` en .env

### 4. URL accesible públicamente

El webhook **debe ser accesible desde Internet**. Para desarrollo local usa [ngrok](https://ngrok.com):

```bash
ngrok http 80
```

Luego usa la URL de ngrok (ej: `https://abc123.ngrok.io/webhooks/square`) en la configuración de Square.

## Endpoint del webhook

- **POST** `/webhooks/square`
- No requiere autenticación
- Recibe eventos de Square en JSON
