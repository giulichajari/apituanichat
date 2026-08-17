<?php

namespace App\Controllers;

use App\Models\OrderModel;
use App\Models\RestaurantModel;
use App\Models\UsersModel;
use App\Services\MailService;
use EasyProjects\SimpleRouter\Router;

class OrderController
{
    private OrderModel $orderModel;
    private RestaurantModel $restaurantModel;
    private UsersModel $usersModel;

    public function __construct()
    {
        $this->orderModel = new OrderModel();
        $this->restaurantModel = new RestaurantModel();
        $this->usersModel = new UsersModel();
    }

    /**
     * Crear pedido de comida y cobrar de inmediato (link Square).
     * El restaurante solo ve el pedido cuando el webhook marca status=paid.
     */
    public function createFoodOrder()
    {
        try {
            $this->createFoodOrderInternal();
        } catch (\Throwable $e) {
            error_log("OrderController createFoodOrder: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            Router::$response->json([
                "message" => "Error al crear el pedido",
                "detail" => $e->getMessage()
            ], 500);
        }
    }

    private function createFoodOrderInternal()
    {
        $body = json_decode(file_get_contents('php://input'), true);

        // Comprador autenticado (opcional) o invitado solo con teléfono
        $user = Router::$request->user ?? null;
        $userId = $user ? ($user->id ?? null) : null;
        $guestEmail = trim((string)($body['guest_email'] ?? ''));
        $restaurantId = (int)($body['restaurantId'] ?? 0);
        $items = $body['items'] ?? [];
        $total = (float)($body['total'] ?? 0);
        $deliveryPhone = trim((string)($body['delivery_phone'] ?? ''));

        if (!$restaurantId || empty($items) || $total <= 0) {
            Router::$response->json([
                "message" => "Campos obligatorios: restaurantId, items, total"
            ], 400);
            return;
        }

        // Si el usuario autenticado no envió teléfono, usar el del perfil
        if ($userId && $deliveryPhone === '') {
            $buyer = $this->usersModel->getUser((int)$userId);
            if (is_array($buyer)) {
                $deliveryPhone = trim((string)($buyer['phone'] ?? ''));
            }
        }

        if ($deliveryPhone === '') {
            Router::$response->json([
                "message" => "El teléfono es obligatorio para el pedido"
            ], 400);
            return;
        }

        if (!$userId) {
            // guest_email opcional (ya no se exige)
            if ($guestEmail !== '' && !filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
                $guestEmail = '';
            }
        }

        $restaurant = $this->restaurantModel->getRestaurantById($restaurantId);
        if (!$restaurant) {
            Router::$response->json(["message" => "Restaurante no encontrado"], 404);
            return;
        }

        if ($total <= 0) {
            Router::$response->json(["message" => "El total debe ser mayor a 0"], 400);
            return;
        }

        $isDelivery = !empty($body['delivery']);
        $orderType = trim((string)($body['order_type'] ?? ''));
        $allowedTypes = ['delivery', 'dine_in', 'reservation'];
        if ($orderType === '' || !in_array($orderType, $allowedTypes, true)) {
            $orderType = $isDelivery ? 'delivery' : 'dine_in';
        }
        if ($orderType === 'delivery') {
            $isDelivery = true;
        } else {
            $isDelivery = false;
        }

        $reservationAt = null;
        if ($orderType === 'delivery') {
            $addr = trim((string)($body['delivery_address'] ?? ''));
            $phone = $deliveryPhone !== '' ? $deliveryPhone : trim((string)($body['delivery_phone'] ?? ''));
            if ($addr === '' || $phone === '') {
                Router::$response->json([
                    "message" => "Para delivery son obligatorios dirección y teléfono"
                ], 400);
                return;
            }
            $deliveryPhone = $phone;
        }

        if ($orderType === 'reservation') {
            $reservationAt = trim((string)($body['reservation_at'] ?? ''));
            if ($reservationAt === '') {
                Router::$response->json([
                    "message" => "Para reservar es obligatoria la fecha y hora (reservation_at)"
                ], 400);
                return;
            }
            $ts = strtotime($reservationAt);
            if ($ts === false || $ts <= time()) {
                Router::$response->json([
                    "message" => "La reserva debe ser en una fecha y hora futura"
                ], 400);
                return;
            }
            $reservationAt = date('Y-m-d H:i:s', $ts);
        }

        $orderId = $this->orderModel->create([
            'user_id' => $userId,
            'guest_email' => $userId ? null : ($guestEmail !== '' ? $guestEmail : null),
            'restaurant_id' => $restaurantId,
            'items' => $items,
            'total' => $total,
            'currency' => 'USD',
            'payment_link_url' => null,
            'idempotency_key' => null,
            'is_delivery' => $isDelivery,
            'delivery_address' => $isDelivery ? trim((string)($body['delivery_address'] ?? '')) : null,
            'delivery_phone' => $deliveryPhone !== '' ? $deliveryPhone : null,
            'order_type' => $orderType,
            'reservation_at' => $reservationAt
        ]);

        if (!$orderId) {
            Router::$response->json(["message" => "Error al crear el pedido"], 500);
            return;
        }

        // Cobro inmediato: crear link Square y devolverlo al cliente
        $order = $this->orderModel->getById($orderId);
        $square = $this->createSquarePaymentLink($orderId, $order, $restaurant);

        if (empty($square['url']) || empty($square['id'])) {
            $this->orderModel->deleteUnpaid($orderId);
            Router::$response->json([
                "message" => "No se pudo iniciar el pago. Intentá de nuevo.",
                "detail" => $square['error'] ?? 'Square payment link no disponible'
            ], 502);
            return;
        }

        $this->orderModel->updatePaymentLink($orderId, $square['url'], $square['id']);

        Router::$response->json([
            "message" => "Pedido creado. Completá el pago para enviarlo al restaurante.",
            "orderId" => $orderId,
            "paymentUrl" => $square['url']
        ], 201);
    }

    /**
     * Crea un payment link de Square (quick_pay) para un food order.
     * @return array{url:?string,id:?string,error:?string}
     */
    private function createSquarePaymentLink(int $orderId, ?array $order, ?array $restaurant): array
    {
        $appEnv = isset($_ENV['APP_ENV']) ? strtolower((string) $_ENV['APP_ENV']) : '';
        $isProd = ($appEnv === 'production');

        if ($isProd) {
            $accessToken = trim((string) ($_ENV['SQUARE_ACCESS_TOKEN_PROD'] ?? $_ENV['SQUARE_ACCESS_TOKEN'] ?? ''));
            $locationId = (string) ($_ENV['SQUARE_LOCATION_ID_PROD'] ?? $_ENV['SQUARE_LOCATION_ID'] ?? '');
        } else {
            $accessToken = trim((string) ($_ENV['SQUARE_ACCESS_TOKEN_SANDBOX'] ?? $_ENV['SQUARE_ACCESS_TOKEN'] ?? ''));
            $locationId = (string) ($_ENV['SQUARE_LOCATION_ID_SANDBOX'] ?? $_ENV['SQUARE_LOCATION_ID'] ?? '');
        }
        $locationId = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($locationId));

        $sandboxEnv = $_ENV['SQUARE_SANDBOX'] ?? '';
        $sandbox = ($sandboxEnv === 'true' || $sandboxEnv === '1') ? true : !$isProd;
        $squareBaseUrl = $sandbox ? 'https://connect.squareupsandbox.com' : 'https://connect.squareup.com';

        if (empty($accessToken) || empty($locationId)) {
            return [
                'url' => null,
                'id' => null,
                'error' => empty($accessToken) ? 'SQUARE_ACCESS_TOKEN no configurado' : 'SQUARE_LOCATION_ID no configurado'
            ];
        }

        $amountCents = (int) round((float) ($order['total'] ?? 0) * 100);
        if ($amountCents <= 0) {
            return ['url' => null, 'id' => null, 'error' => 'Total inválido para cobro'];
        }

        $currency = strtoupper((string) ($order['currency'] ?? 'USD'));
        $idempotencyKey = uniqid('food_', true);
        $postData = [
            "idempotency_key" => $idempotencyKey,
            "quick_pay" => [
                "name" => "Pedido #{$orderId} Tuani Eats - " . ($restaurant['nombre'] ?? 'Restaurante'),
                "price_money" => [
                    "amount" => $amountCents,
                    "currency" => $currency
                ],
                "location_id" => $locationId
            ]
        ];

        $ch = curl_init($squareBaseUrl . "/v2/online-checkout/payment-links");
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Bearer $accessToken",
            "Square-Version: 2024-11-20"
        ]);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $this->paymentLog("createSquarePaymentLink HTTP", $httpcode);
        $this->paymentLog("createSquarePaymentLink RESP", substr($response ?: '', 0, 800));

        $result = is_string($response) ? json_decode($response, true) : [];
        $paymentLinkUrl = $result['payment_link']['url'] ?? null;
        $squarePaymentLinkId = $result['payment_link']['id'] ?? null;

        $error = null;
        if ($curlError) {
            $error = $curlError;
        } elseif (($httpcode !== 200 && $httpcode !== 201) || !$paymentLinkUrl) {
            if (!empty($result['errors']) && is_array($result['errors'])) {
                $first = $result['errors'][0] ?? [];
                $error = ($first['code'] ?? '') . ': ' . ($first['detail'] ?? $first['message'] ?? json_encode($first));
            } else {
                $error = 'Square no devolvió payment link';
            }
        }

        return [
            'url' => $paymentLinkUrl,
            'id' => $squarePaymentLinkId,
            'error' => $error
        ];
    }

    /**
     * Obtener pedidos de un restaurante (solo propietario)
     */
    public function getOrdersByRestaurant($restaurantId)
    {
        $restaurantId = (int)$restaurantId;
        $user = Router::$request->user ?? null;
        $userId = $user ? (int)(is_object($user) ? $user->id : ($user['id'] ?? 0)) : null;

        if (!$userId) {
            Router::$response->json(["message" => "No autenticado"], 401);
            return;
        }

        $restaurant = $this->restaurantModel->getRestaurantById($restaurantId);
        if (!$restaurant) {
            Router::$response->json(["message" => "Restaurante no encontrado"], 404);
            return;
        }
        $ownerId = (int)($restaurant['user_id'] ?? 0);
        if ($ownerId !== $userId) {
            Router::$response->json(["message" => "No tienes permisos para ver pedidos de este restaurante"], 403);
            return;
        }

        $orders = $this->orderModel->getByRestaurant($restaurantId);

        Router::$response->json([
            "data" => $orders,
            "message" => "Pedidos obtenidos correctamente"
        ], 200);
    }
    private function paymentLog(string $message, $data = null): void
    {
        $logsDir = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
        $file = $logsDir . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'orders.log';

        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0755, true);
        }
    
        $line = "[" . date("Y-m-d H:i:s") . "] " . $message;
    
        if ($data !== null) {
            if (is_array($data) || is_object($data)) {
                $line .= " | " . json_encode($data, JSON_UNESCAPED_UNICODE);
            } else {
                $line .= " | " . $data;
            }
        }
    
        file_put_contents($file, $line . PHP_EOL, FILE_APPEND);
    }
    
    /**
     * Confirmar pedido (solo propietario del restaurante).
     * Crea link de pago Square y envía email al comprador.
     */
    public function confirmOrder($orderId)
    {
        try {
            $this->confirmOrderInternal($orderId);
        } catch (\Throwable $e) {
            $this->paymentLog("confirmOrder EXCEPTION", $e->getMessage());
            error_log("OrderController confirmOrder: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            Router::$response->json([
                "message" => "Error al confirmar el pedido",
                "detail" => $e->getMessage()
            ], 500);
        }
    }

    private function confirmOrderInternal($orderId)
    {
    $this->paymentLog("==== confirmOrder START ====", $orderId);

    $orderId = (int)$orderId;
    $userId = Router::$request->user->id ?? null;

    $this->paymentLog("UserId", $userId);

    if (!$userId) {
        $this->paymentLog("ERROR: No autenticado");
        Router::$response->json(["message" => "No autenticado"], 401);
        return;
    }

    if (!$this->orderModel->belongsToRestaurantOwner($orderId, $userId)) {
        $this->paymentLog("ERROR: No pertenece al restaurante");
        Router::$response->json(["message" => "No tienes permisos"], 403);
        return;
    }

    $order = $this->orderModel->getById($orderId);
    $this->paymentLog("Order", $order);

    if (!$order) {
        $this->paymentLog("ERROR: Pedido no encontrado");
        Router::$response->json(["message" => "Pedido no encontrado"], 404);
        return;
    }

    $ok = $this->orderModel->confirmOrder($orderId, $order['restaurant_id']);
    $this->paymentLog("ConfirmOrder DB result", $ok);

    if (!$ok) {
        Router::$response->json(["message" => "No se pudo confirmar"], 400);
        return;
    }

    // ================== SQUARE ==================
    // Soporte: SQUARE_ACCESS_TOKEN / SQUARE_LOCATION_ID o bien _PROD / _SANDBOX según APP_ENV
    $appEnv = isset($_ENV['APP_ENV']) ? strtolower((string) $_ENV['APP_ENV']) : '';
    $isProd = ($appEnv === 'production');

    if ($isProd) {
        $accessToken = trim((string) ($_ENV['SQUARE_ACCESS_TOKEN_PROD'] ?? $_ENV['SQUARE_ACCESS_TOKEN'] ?? ''));
        $locationId = (string) ($_ENV['SQUARE_LOCATION_ID_PROD'] ?? $_ENV['SQUARE_LOCATION_ID'] ?? '');
    } else {
        $accessToken = trim((string) ($_ENV['SQUARE_ACCESS_TOKEN_SANDBOX'] ?? $_ENV['SQUARE_ACCESS_TOKEN'] ?? ''));
        $locationId = (string) ($_ENV['SQUARE_LOCATION_ID_SANDBOX'] ?? $_ENV['SQUARE_LOCATION_ID'] ?? '');
    }
    // Square solo acepta location_id con letras, números, guión y guión bajo; quitar todo lo demás
    $locationId = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($locationId));

    $sandboxEnv = $_ENV['SQUARE_SANDBOX'] ?? '';
    $sandbox = ($sandboxEnv === 'true' || $sandboxEnv === '1') ? true : !$isProd;
    $squareBaseUrl = $sandbox ? 'https://connect.squareupsandbox.com' : 'https://connect.squareup.com';

    $this->paymentLog("Square token present", !empty($accessToken));
    $this->paymentLog("Square location", $locationId);
    $this->paymentLog("Square sandbox", $sandbox);

    $restaurant = $this->restaurantModel->getRestaurantById($order['restaurant_id']);

    $paymentLinkUrl = null;
    $squarePaymentLinkId = null;
    $squareErrorDetail = null;

    if (!empty($accessToken) && !empty($locationId)) {
        // La app envía el total en dólares y currency USD; Square recibe eso tal cual
        $amountCents = (int) round((float) $order['total'] * 100);
        $currency = strtoupper((string)($order['currency'] ?? 'USD'));
        $idempotencyKey = uniqid('food_', true);
        $locationIdForSquare = preg_replace('/[^a-zA-Z0-9_-]/', '', trim((string) $locationId));
        $this->paymentLog("Square location_id ENVIADO (length " . strlen($locationIdForSquare) . ")", $locationIdForSquare);

        $postData = [
            "idempotency_key" => $idempotencyKey,
            "quick_pay" => [
                "name" => "Pedido #{$orderId} Tuani Eats - " . ($restaurant['nombre'] ?? 'Restaurante'),
                "price_money" => [
                    "amount" => $amountCents,
                    "currency" => $currency
                ],
                "location_id" => $locationIdForSquare
            ]
        ];

        $ch = curl_init($squareBaseUrl . "/v2/online-checkout/payment-links");
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Bearer $accessToken",
            "Square-Version: 2024-11-20"
        ]);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $this->paymentLog("Square HTTP CODE", $httpcode);
        $this->paymentLog("Square RESPONSE", substr($response ?: '', 0, 800));
        if ($curlError) {
            $this->paymentLog("Square CURL ERROR", $curlError);
            $squareErrorDetail = $curlError;
        }

        $result = is_string($response) ? json_decode($response, true) : [];
        $paymentLinkUrl = $result['payment_link']['url'] ?? null;
        $squarePaymentLinkId = $result['payment_link']['id'] ?? null;

        if (($httpcode !== 200 && $httpcode !== 201) || !$paymentLinkUrl) {
            $squareErrorDetail = $squareErrorDetail ?? '';
            if (!empty($result['errors']) && is_array($result['errors'])) {
                $first = $result['errors'][0] ?? [];
                $squareErrorDetail = ($first['code'] ?? '') . ': ' . ($first['detail'] ?? $first['message'] ?? json_encode($first));
            }
        }
    } else {
        $squareErrorDetail = empty($accessToken) ? 'SQUARE_ACCESS_TOKEN no configurado' : 'SQUARE_LOCATION_ID no configurado';
    }

    if ($paymentLinkUrl && $squarePaymentLinkId) {
        $this->orderModel->updatePaymentLink($orderId, $paymentLinkUrl, $squarePaymentLinkId);
        $this->paymentLog("PaymentLink", $paymentLinkUrl);
    }

    // ================== EMAIL (siempre si hay comprador: con link o aviso de problema) ==================

    $buyerEmail = $order['user_email'] ?? null;

    if (!$buyerEmail && !empty($order['guest_email'])) {
        $buyerEmail = $order['guest_email'];
    }

    if (!$buyerEmail && !empty($order['user_id'])) {
        $buyer = $this->usersModel->getUser((int)$order['user_id']);
        $this->paymentLog("Buyer lookup", $buyer);
        $buyerEmail = $buyer['email'] ?? null;
    }

    $this->paymentLog("EMAIL FINAL (comprador del pedido)", ['email' => $buyerEmail, 'user_id' => $order['user_id'] ?? null]);

    $emailSent = false;

    if ($buyerEmail) {
        if ($paymentLinkUrl) {
            $emailSent = $this->sendPaymentEmail($buyerEmail, $order, $paymentLinkUrl);
        } else {
            $emailSent = $this->sendPaymentEmailConfirmOnly($buyerEmail, $order, $squareErrorDetail);
        }
    } else {
        $this->paymentLog("NO HAY EMAIL PARA ENVIAR");
    }

    // El pedido ya está confirmado en BD. Si Square falló, no devolver 500 para no desloguear al usuario.
    if (!$paymentLinkUrl) {
        $this->paymentLog("WARN: link de pago no generado", $squareErrorDetail);
    }

    $this->paymentLog("EMAIL SENT RESULT", $emailSent);

    Router::$response->json([
        "message" => "Pedido confirmado",
        "payment_link_sent" => (bool)$paymentLinkUrl,
        "detail" => !$paymentLinkUrl ? ($squareErrorDetail ?? 'Link de pago no disponible') : null,
        "data" => $this->orderModel->getById($orderId)
    ], 200);
}
/**
 * Envía email cuando el pedido está confirmado pero no se pudo generar el link de pago (Square falló).
 */
private function sendPaymentEmailConfirmOnly(string $to, array $order, ?string $reason = null): bool
{
    $this->paymentLog("sendPaymentEmailConfirmOnly TO", $to);

    $subject = "Tuani Eats - Pedido #{$order['id']} confirmado";
    $total = number_format((float)($order['total'] ?? 0), 2);

    $body = "Hola,\n\n";
    $body .= "Tu pedido #{$order['id']} ha sido confirmado por el restaurante.\n\n";
    $body .= "Total: \${$total} " . ($order['currency'] ?? 'ARS') . "\n\n";
    $body .= "No pudimos generar el link de pago en este momento. El restaurante te contactará para indicarte cómo completar el pago.\n\n";
    $body .= "Gracias por usar Tuani Eats.";

    return $this->sendEmail($to, $subject, $body);
}

private function sendPaymentEmail(string $to, array $order, string $paymentUrl): bool
{
    $this->paymentLog("sendPaymentEmail TO", $to);

    $subject = "Tuani Eats - Completa el pago de tu pedido #{$order['id']}";
    $total = number_format((float)($order['total'] ?? 0), 2);

    $body = "Hola,\n\n";
    $body .= "Tu pedido #{$order['id']} ha sido confirmado.\n\n";
    $body .= "Total: \${$total} " . ($order['currency'] ?? 'ARS') . "\n\n";
    $body .= "Pagar aquí:\n$paymentUrl\n\n";
    $body .= "Gracias por usar Tuani Eats.";

    return $this->sendEmail($to, $subject, $body);
}

private function sendEmail(string $to, string $subject, string $body): bool
{
    $sent = MailService::send($to, $subject, $body, 'Tuani Eats');
    $this->paymentLog("sendEmail RESULT", $sent ? 'OK' : 'FAIL');
    return $sent;
}

}
