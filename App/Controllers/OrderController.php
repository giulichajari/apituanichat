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

    /**
     * Confirmar pedido (solo propietario del restaurante).
     * Crea link de pago Square y envía email al comprador.
     */
    public function confirmOrder($orderId)
    {
        error_log("==== confirmOrder START orderId={$orderId} ====");
    
        $orderId = (int)$orderId;
        $userId = Router::$request->user->id ?? null;
    
        error_log("UserId: " . ($userId ?? 'NULL'));
    
        if (!$userId) {
            error_log("No autenticado");
            Router::$response->status(401)->json(["message" => "No autenticado"]);
            return;
        }
    
        if (!$this->orderModel->belongsToRestaurantOwner($orderId, $userId)) {
            error_log("No pertenece al restaurante");
            Router::$response->status(403)->json(["message" => "No tienes permisos"]);
            return;
        }
    
        $order = $this->orderModel->getById($orderId);
        error_log("Order: " . json_encode($order));
    
        if (!$order) {
            error_log("Pedido no encontrado");
            Router::$response->status(404)->json(["message" => "Pedido no encontrado"]);
            return;
        }
    
        $ok = $this->orderModel->confirmOrder($orderId, $order['restaurant_id']);
        error_log("ConfirmOrder DB result: " . ($ok ? 'OK' : 'FAIL'));
    
        if (!$ok) {
            Router::$response->status(400)->json(["message" => "No se pudo confirmar"]);
            return;
        }
    
        // ================== SQUARE ==================
        error_log("Creando link de pago Square");

        $accessToken = $_ENV['SQUARE_ACCESS_TOKEN'] ?? $_ENV['SQUARE_ACCESS_TOKEN_SANDBOX'] ?? $_ENV['SQUARE_ACCESS_TOKEN_PROD'] ?? '';
        $locationId = $_ENV['SQUARE_LOCATION_ID'] ?? $_ENV['SQUARE_LOCATION_ID_SANDBOX'] ?? $_ENV['SQUARE_LOCATION_ID_PROD'] ?? '';

        error_log("Square token present: " . (!empty($accessToken) ? 'SI' : 'NO'));
        error_log("Square location: " . $locationId);

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

        $squareBase = ($_ENV['APP_ENV'] ?? 'development') === 'production'
            ? 'https://connect.squareup.com'
            : 'https://connect.squareupsandbox.com';
        $ch = curl_init("{$squareBase}/v2/online-checkout/payment-links");
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

        $result = json_decode($response, true);
        $paymentLinkUrl = $result['payment_link']['url'] ?? null;
        $squarePaymentLinkId = $result['payment_link']['id'] ?? null;

        error_log("Square HTTP CODE: " . $httpcode);
        error_log("Square RESPONSE: " . substr($response ?: '', 0, 200));

        if (($httpcode !== 200 && $httpcode !== 201) || !$paymentLinkUrl) {
            error_log("ERROR creando link de pago");
            Router::$response->status(500)->json([
                "message" => "Falló crear link de pago Square"
            ]);
            return;
        }

        if ($squarePaymentLinkId) {
            $this->orderModel->updatePaymentLink($orderId, $paymentLinkUrl, $squarePaymentLinkId);
        }

        error_log("PaymentLink: " . $paymentLinkUrl);
    
        // ================== EMAIL ==================
        $buyerEmail = $order['user_email'] ?? null;
    
        if (!$buyerEmail && !empty($order['user_id'])) {
            $buyer = $this->usersModel->getUser((int)$order['user_id']);
            error_log("Buyer lookup: " . json_encode($buyer));
    
            $buyerEmail = (is_array($buyer) && !empty($buyer['email']))
                ? $buyer['email']
                : null;
        }
    
        error_log("EMAIL FINAL: " . ($buyerEmail ?? 'NULL'));
    
        $emailSent = false;
    
        if ($buyerEmail) {
            $emailSent = $this->sendPaymentEmail($buyerEmail, $order, $paymentLinkUrl);
        } else {
            error_log("NO HAY EMAIL PARA ENVIAR");
        }
    
        error_log("EMAIL SENT RESULT: " . ($emailSent ? 'SI' : 'NO'));
    
        Router::$response->status(200)->json([
            "message" => "Pedido confirmado",
            "data" => $this->orderModel->getById($orderId)
        ]);
    }

    private function sendPaymentEmail(string $to, array $order, string $paymentUrl): bool
    {
        $subject = "Tuani Eats - Completa el pago de tu pedido #{$order['id']}";
        $total = number_format((float)($order['total'] ?? 0), 2);
        $body = "Hola,\n\n";
        $body .= "Tu pedido #{$order['id']} ha sido confirmado por el restaurante.\n\n";
        $body .= "Total: \${$total} " . ($order['currency'] ?? 'ARS') . "\n\n";
        $body .= "Para completar el pago, haz clic en el siguiente enlace:\n";
        $body .= $paymentUrl . "\n\n";
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
                $mail->SMTPAuth = !empty($_ENV['SMTP_USER']);
                if ($mail->SMTPAuth) {
                    $mail->Username = $_ENV['SMTP_USER'];
                    $mail->Password = $_ENV['SMTP_PASS'] ?? '';
                }
                $mail->SMTPSecure = $_ENV['SMTP_SECURE'] ?? 'tls';
                $mail->Port = (int)($_ENV['SMTP_PORT'] ?? 587);
                $mail->setFrom($from, 'Tuani Eats');
                $mail->addReplyTo($replyTo);
                $mail->addAddress($to);
                $mail->Subject = $subject;
                $mail->Body = $body;
                $mail->isHTML(false);
                $mail->send();
                return true;
            } catch (Exception $e) {
                error_log("OrderController sendPaymentEmail PHPMailer: " . $e->getMessage());
                return false;
            }
        }

        $headers = "From: $from\r\n";
        $headers .= "Reply-To: $replyTo\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $sent = @mail($to, $subject, $body, $headers);
        if (!$sent) {
            error_log("OrderController sendPaymentEmail: mail() falló.");
        }
        return $sent;
    }
}
