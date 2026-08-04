📋 Resumen de la auditoría — TuaniChat
🚨 CRÍTICOS (arreglar ya, hoy)
1. Secreto JWT es un placeholder sin cambiar

Dónde: App/Middlewares/TokenMiddleware.php

php
private string $secret = "TU_SECRET_KEY";

Riesgo: Cualquiera puede generar un token JWT válido para cualquier usuario, incluido un admin, sin contraseña. Es la puerta de entrada a todo lo demás.
Solución:

php
private string $secret;
public function __construct(...) {
    $this->secret = $_ENV['JWT_SECRET'] ?? throw new \RuntimeException('JWT_SECRET no configurado');
}

Genera un secreto largo y random (openssl rand -base64 64), ponlo en .env como JWT_SECRET=..., y todos los tokens ya emitidos quedan inválidos al cambiarlo — es esperado, todos tendrán que volver a loguearse.

2. ProductController::isAdmin() siempre devuelve true

Dónde: App/Controllers/ProductController.php línea 1029

php
private function isAdmin($userId) { return true; } // Temporal

Riesgo: Cualquier usuario autenticado puede aprobar/rechazar productos de la tienda.
Solución: conectar con el modelo real, mismo patrón que ya usan bien en otros controladores:

php
private function isAdmin($userId) {
    $user = $this->userModel->getUser($userId);
    return $user && strtoupper($user['rol'] ?? '') === 'ADMIN';
}
3. El precio de viajes se confía del cliente, no se recalcula en el servidor

Dónde: App/Controllers/PaymentController.php

php
$estimatedFare = $body['estimatedFare'] ?? null;
$amountCents = (int) round($estimatedFare * 100); // se manda directo a Square

Riesgo: Cualquiera puede interceptar la petición (DevTools, Postman) y pagar lo que quiera por un viaje.
Solución: el backend debe recalcular la tarifa usando driverId, distancia/duración reales y las tarifas guardadas en base de datos — nunca confiar en estimatedFare del body.

4. userId también se confía del cliente en pagos

Mismo archivo: $userId = $body['userId'], en vez de sacarlo del token ya verificado (Router::$request->user->id).
Riesgo: un usuario logueado puede crear pagos a nombre de otro usuario.
Solución:

php
$userId = Router::$request->user->id; // del token, no del body
🟠 ALTOS (arreglar esta semana)
5. Rol de usuario controlado desde el cliente para decisiones de UI

localStorage.getItem('rol') decide qué se muestra en el frontend. Ya confirmamos que varios endpoints admin sí validan en servidor (bien), pero no todos — hay que auditar cada endpoint sensible uno por uno para confirmar que ninguno se salta esta validación (el caso de ProductController es la prueba de que puede pasar).

6. Datos financieros/identidad de conductores (SSN, cuenta bancaria, licencia)

Revisa si estos campos se guardan cifrados en la base de datos, no en texto plano. Si usan MySQL, considera cifrado a nivel de aplicación (AES) para estos campos específicos, no solo TLS en tránsito.

7. Llamada a OpenAI directo desde el navegador con API key expuesta

src/utils/botUtils.js — mover esta llamada a un endpoint propio del backend para no exponer la key.

🟡 MEDIOS
8. Credencial TURN de ejemplo hardcodeada como fallback

"ClaveSuperSegura123" en config.js — confirma que uses una real vía .env en producción.

9. Token JWT en localStorage

Vulnerable a robo vía XSS. Considerar migrar a cookies httpOnly a futuro (cambio grande, no urgente).

10. Exceso de console.log con datos de usuario en producción

Decenas de archivos. Quedan visibles en la consola del navegador de cualquiera. Recomiendo un wrapper:

js
const log = process.env.NODE_ENV === 'development' ? console.log : () => {};

y reemplazar console.log por log (búsqueda y reemplazo global).

11. Código muerto/legado con lógica de servidor mezclada en el frontend

ChatPage.js tiene código de servidor WebSocket (findUserWebSocket, ws.send) que no debería ejecutarse en el navegador — parece no usarse, pero conviene eliminarlo para evitar confusión y errores futuros.

12. isAdmin de rol duplicado en múltiples case del switch en SettingsPage.js

No es vulnerabilidad, pero hay bloques case 'my-restaurants' repetidos — JS solo ejecuta el primero, el resto es código muerto que conviene limpiar.

✅ Lo que está BIEN hecho
Contraseñas con password_hash/password_verify (bcrypt) — correcto.
La mayoría de rutas admin (CountryRateController, RestaurantController, DriverApplicationController) sí validan rol server-side.
Casi todos los endpoints usan Authorization: Bearer correctamente.
Buena separación frontend/backend, estructura MVC clara en el backend.

Prioridad de acción para hoy mismo: arregla el punto #1 (secreto JWT) antes que cualquier otra cosa — mientras esa clave siga siendo "TU_SECRET_KEY", todo lo demás que arreglemos es secundario, porque un atacante puede saltarse la autenticación por completo.