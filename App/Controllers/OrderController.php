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

        $orderId = $this->orderModel->create([
            'user_id' => $userId,
            'restaurant_id' => $restaurantId,
            'items' => $items,
            'total' => $total,
            'currency' => $body['currency'] ?? 'ARS',
            'payment_link_url' => null,
            'idempotency_key' => null
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
        $file = __DIR__ . '/../logs/payment.log';
    
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
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

    $this->paymentLog("Square token present", !empty($accessToken));
    $this->paymentLog("Square location", $locationId);

    $restaurant = $this->restaurantModel->getRestaurantById($order['restaurant_id']);

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

    $ch = curl_init("https://connect.squareupsandbox.com/v2/online-checkout/payment-links");

    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer $accessToken",
        "Square-Version: 2025-03-19"
    ]);

    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    $this->paymentLog("Square HTTP CODE", $httpcode);
    $this->paymentLog("Square RESPONSE", substr($response ?: '', 0, 500));

    $result = json_decode($response, true);

    $paymentLinkUrl = $result['payment_link']['url'] ?? null;
    $squarePaymentLinkId = $result['payment_link']['id'] ?? null;

    if (($httpcode !== 200 && $httpcode !== 201) || !$paymentLinkUrl) {
        $this->paymentLog("ERROR creando link de pago");
        Router::$response->status(500)->json([
            "message" => "Falló crear link de pago Square"
        ]);
        return;
    }

    $this->orderModel->updatePaymentLink($orderId, $paymentLinkUrl, $squarePaymentLinkId);

    $this->paymentLog("PaymentLink", $paymentLinkUrl);

    // ================== EMAIL ==================

    $buyerEmail = $order['user_email'] ?? null;

    if (!$buyerEmail && !empty($order['user_id'])) {
        $buyer = $this->usersModel->getUser((int)$order['user_id']);
        $this->paymentLog("Buyer lookup", $buyer);

        $buyerEmail = $buyer['email'] ?? null;
    }

    $this->paymentLog("EMAIL FINAL", $buyerEmail);

    $emailSent = false;

    if ($buyerEmail) {
        $emailSent = $this->sendPaymentEmail($buyerEmail, $order, $paymentLinkUrl);
    } else {
        $this->paymentLog("NO HAY EMAIL PARA ENVIAR");
    }

    $this->paymentLog("EMAIL SENT RESULT", $emailSent);

    Router::$response->status(200)->json([
        "message" => "Pedido confirmado",
        "data" => $this->orderModel->getById($orderId)
    ]);
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
