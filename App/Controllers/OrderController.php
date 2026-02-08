<?php

namespace App\Controllers;

use App\Models\OrderModel;
use App\Models\RestaurantModel;
use EasyProjects\SimpleRouter\Router;

class OrderController
{
    private OrderModel $orderModel;
    private RestaurantModel $restaurantModel;

    public function __construct()
    {
        $this->orderModel = new OrderModel();
        $this->restaurantModel = new RestaurantModel();
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
        $orderId = (int)$orderId;
        $userId = Router::$request->user->id ?? null;

        if (!$userId) {
            Router::$response->status(401)->json(["message" => "No autenticado"]);
            return;
        }

        if (!$this->orderModel->belongsToRestaurantOwner($orderId, $userId)) {
            Router::$response->status(403)->json(["message" => "No tienes permisos para confirmar este pedido"]);
            return;
        }

        $order = $this->orderModel->getById($orderId);
        if (!$order) {
            Router::$response->status(404)->json(["message" => "Pedido no encontrado"]);
            return;
        }

        $ok = $this->orderModel->confirmOrder($orderId, $order['restaurant_id']);
        if (!$ok) {
            Router::$response->status(400)->json(["message" => "No se pudo confirmar (quizá ya está confirmado)"]);
            return;
        }

        // Crear link de pago Square y guardarlo
        $restaurant = $this->restaurantModel->getRestaurantById($order['restaurant_id']);
        $accessToken = $_ENV['SQUARE_ACCESS_TOKEN'] ?? '';
        $locationId = $_ENV['SQUARE_LOCATION_ID'] ?? '';
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

        $result = json_decode($response, true);
        $paymentLinkUrl = $result['payment_link']['url'] ?? null;
        $squarePaymentLinkId = $result['payment_link']['id'] ?? null;

        if ($httpcode !== 200 && $httpcode !== 201 || !$paymentLinkUrl) {
            error_log("Square payment link error: " . ($response ?: 'no response'));
            Router::$response->status(500)->json([
                "message" => "Pedido confirmado pero falló crear link de pago. Contacta soporte.",
                "data" => $this->orderModel->getById($orderId)
            ]);
            return;
        }

        if ($squarePaymentLinkId) {
            $this->orderModel->updatePaymentLink($orderId, $paymentLinkUrl, $squarePaymentLinkId);
        }

        // Enviar email al comprador con el link de pago
        $buyerEmail = $order['user_email'] ?? null;
        if ($buyerEmail) {
            $this->sendPaymentEmail($buyerEmail, $order, $paymentLinkUrl);
        }

        Router::$response->status(200)->json([
            "message" => "Pedido confirmado. Se envió email al comprador con el link de pago.",
            "data" => $this->orderModel->getById($orderId)
        ]);
    }

    private function sendPaymentEmail(string $to, array $order, string $paymentUrl): void
    {
        $subject = "Tuani Eats - Completa el pago de tu pedido #{$order['id']}";
        $total = number_format((float)($order['total'] ?? 0), 2);
        $body = "Hola,\n\n";
        $body .= "Tu pedido #{$order['id']} ha sido confirmado por el restaurante.\n\n";
        $body .= "Total: \${$total} {$order['currency']}\n\n";
        $body .= "Para completar el pago, haz clic en el siguiente enlace:\n";
        $body .= $paymentUrl . "\n\n";
        $body .= "Gracias por usar Tuani Eats.";

        $headers = "From: " . ($_ENV['MAIL_FROM'] ?? 'soporte@tuanichat.com') . "\r\n";
        $headers .= "Reply-To: " . ($_ENV['MAIL_REPLY'] ?? 'soporte@tuanichat.com') . "\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

        @mail($to, $subject, $body, $headers);
    }
}
