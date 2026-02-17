<?php

namespace App\Controllers;

use App\Models\OrderModel;
use App\Models\RestaurantModel;
use App\Models\UsersModel;
use EasyProjects\SimpleRouter\Router;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
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
    
        $accessToken = $_ENV['SQUARE_ACCESS_TOKEN'] ?? '';
        $locationId = $_ENV['SQUARE_LOCATION_ID'] ?? '';
    
        error_log("Square token present: " . (!empty($accessToken) ? 'SI' : 'NO'));
        error_log("Square location: " . $locationId);
    
        // (tu código de Square igual…)
    
        error_log("Square HTTP CODE: " . $httpcode);
        error_log("Square RESPONSE: " . $response);
    
        if ($httpcode !== 200 && $httpcode !== 201 || !$paymentLinkUrl) {
            error_log("ERROR creando link de pago");
            Router::$response->status(500)->json([
                "message" => "Falló Square"
            ]);
            return;
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
    
}
