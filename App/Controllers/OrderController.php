<?php

namespace App\Controllers;

use App\Models\OrderModel;
use App\Models\RestaurantModel;
use App\Models\UsersModel;
use EasyProjects\SimpleRouter\Router;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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
     * Crear pedido de comida (sin pago aún; el link de pago se envía por email al confirmar)
     */
    public function createFoodOrder()
    {
        $body = json_decode(file_get_contents('php://input'), true);

        $userId = $body['userId'] ?? Router::$request->user->id ?? null;
        $restaurantId = (int)($body['restaurantId'] ?? 0);
        $items = $body['items'] ?? [];
        $total = (float)($body['total'] ?? 0);

        if (!$userId || !$restaurantId || empty($items) || $total <= 0) {
            Router::$response->status(400)->json([
                "message" => "Campos obligatorios: userId, restaurantId, items, total"
            ]);
            return;
        }

        $restaurant = $this->restaurantModel->getRestaurantById($restaurantId);
        if (!$restaurant) {
            Router::$response->status(404)->json(["message" => "Restaurante no encontrado"]);
            return;
        }

        if ($total <= 0) {
            Router::$response->status(400)->json(["message" => "El total debe ser mayor a 0"]);
            return;
        }

        $isDelivery = !empty($body['delivery']);
        if ($isDelivery) {
            $addr = trim((string)($body['delivery_address'] ?? ''));
            $phone = trim((string)($body['delivery_phone'] ?? ''));
            if ($addr === '' || $phone === '') {
                Router::$response->status(400)->json([
                    "message" => "Para delivery son obligatorios dirección y teléfono"
                ]);
                return;
            }
        }

        $orderId = $this->orderModel->create([
            'user_id' => $userId,
            'restaurant_id' => $restaurantId,
            'items' => $items,
            'total' => $total,
            'currency' => $body['currency'] ?? 'ARS',
            'payment_link_url' => null,
            'idempotency_key' => null,
            'is_delivery' => $isDelivery,
            'delivery_address' => $isDelivery ? trim((string)($body['delivery_address'] ?? '')) : null,
            'delivery_phone' => $isDelivery ? trim((string)($body['delivery_phone'] ?? '')) : null
        ]);

        if (!$orderId) {
            Router::$response->status(500)->json(["message" => "Error al crear el pedido"]);
            return;
        }

        Router::$response->status(201)->json([
            "message" => "Pedido creado. Espera la confirmación del restaurante. Recibirás un email para completar el pago.",
            "orderId" => $orderId
        ]);
    }

    /**
     * Obtener pedidos de un restaurante (solo propietario)
     */
    public function getOrdersByRestaurant($restaurantId)
    {
        $restaurantId = (int)$restaurantId;
        $userId = Router::$request->user->id ?? null;

        if (!$userId) {
            Router::$response->status(401)->json(["message" => "No autenticado"]);
            return;
        }

        $restaurant = $this->restaurantModel->getRestaurantById($restaurantId);
        if (!$restaurant || $restaurant['user_id'] != $userId) {
            Router::$response->status(403)->json(["message" => "No tienes permisos para ver pedidos de este restaurante"]);
            return;
        }

        $orders = $this->orderModel->getByRestaurant($restaurantId);

        Router::$response->status(200)->json([
            "data" => $orders,
            "message" => "Pedidos obtenidos correctamente"
        ]);
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
            Router::$response->status(500)->json([
                "message" => "Error al confirmar el pedido",
                "detail" => $e->getMessage()
            ]);
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
        Router::$response->status(401)->json(["message" => "No autenticado"]);
        return;
    }

    if (!$this->orderModel->belongsToRestaurantOwner($orderId, $userId)) {
        $this->paymentLog("ERROR: No pertenece al restaurante");
        Router::$response->status(403)->json(["message" => "No tienes permisos"]);
        return;
    }

    $order = $this->orderModel->getById($orderId);
    $this->paymentLog("Order", $order);

    if (!$order) {
        $this->paymentLog("ERROR: Pedido no encontrado");
        Router::$response->status(404)->json(["message" => "Pedido no encontrado"]);
        return;
    }

    $ok = $this->orderModel->confirmOrder($orderId, $order['restaurant_id']);
    $this->paymentLog("ConfirmOrder DB result", $ok);

    if (!$ok) {
        Router::$response->status(400)->json(["message" => "No se pudo confirmar"]);
        return;
    }

    // ================== SQUARE ==================
    $accessToken = $_ENV['SQUARE_ACCESS_TOKEN'] ?? '';
    $locationId = $_ENV['SQUARE_LOCATION_ID'] ?? '';
    $sandbox = (isset($_ENV['SQUARE_SANDBOX']) ? (bool)($_ENV['SQUARE_SANDBOX'] === 'true' || $_ENV['SQUARE_SANDBOX'] === '1') : true);
    $squareBaseUrl = $sandbox ? 'https://connect.squareupsandbox.com' : 'https://connect.squareup.com';

    $this->paymentLog("Square token present", !empty($accessToken));
    $this->paymentLog("Square location", $locationId);
    $this->paymentLog("Square sandbox", $sandbox);

    $restaurant = $this->restaurantModel->getRestaurantById($order['restaurant_id']);

    $paymentLinkUrl = null;
    $squarePaymentLinkId = null;
    $squareErrorDetail = null;

    if (!empty($accessToken) && !empty($locationId)) {
        $amountCents = (int) round((float)$order['total'] * 100);
        $currency = $order['currency'] ?? 'ARS';
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

    if (!$buyerEmail && !empty($order['user_id'])) {
        $buyer = $this->usersModel->getUser((int)$order['user_id']);
        $this->paymentLog("Buyer lookup", $buyer);
        $buyerEmail = $buyer['email'] ?? null;
    }

    $this->paymentLog("EMAIL FINAL", $buyerEmail);

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

    Router::$response->status(200)->json([
        "message" => "Pedido confirmado",
        "payment_link_sent" => (bool)$paymentLinkUrl,
        "detail" => !$paymentLinkUrl ? ($squareErrorDetail ?? 'Link de pago no disponible') : null,
        "data" => $this->orderModel->getById($orderId)
    ]);
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
    $from = $_ENV['MAIL_FROM'] ?? 'noreply@tuanichat.com';
    $replyTo = $_ENV['MAIL_REPLY'] ?? 'soporte@tuanichat.com';
    $smtpHost = $_ENV['SMTP_HOST'] ?? null;

    if ($smtpHost) {
        try {
            $mail = new PHPMailer(true);
            $mail->CharSet = 'UTF-8';
            $mail->isSMTP();
            $mail->Host = $smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = $_ENV['SMTP_USER'];
            $mail->Password = $_ENV['SMTP_PASS'];
            $mail->SMTPSecure = $_ENV['SMTP_SECURE'] ?? 'tls';
            $mail->Port = (int)($_ENV['SMTP_PORT'] ?? 587);
            $mail->setFrom($from, 'Tuani Eats');
            $mail->addReplyTo($replyTo);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->send();
            $this->paymentLog("PHPMailer RESULT", "OK");
            return true;
        } catch (Exception $e) {
            $this->paymentLog("PHPMailer ERROR", $e->getMessage());
            return false;
        }
    }

    $headers = "From: $from\r\n";
    $headers .= "Reply-To: $replyTo\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $sent = @mail($to, $subject, $body, $headers);
    $this->paymentLog("mail() RESULT", $sent);
    return $sent;
}

}
