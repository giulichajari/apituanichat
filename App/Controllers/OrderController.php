<?php

namespace App\Controllers;

use App\Models\OrderModel;
use App\Models\RestaurantModel;
use App\Models\UsersModel;
use App\Models\WalletModel;
use App\Models\ReceiptModel;
use App\Services\MailService;
use App\Services\DeliveryDispatchService;
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
     * Crear pedido de comida y cobrar de forma atómica con Wallet.
     * El restaurante recibe únicamente pedidos con pago confirmado.
     */
    public function createFoodOrder()
    {
        try {
            $this->createFoodOrderInternal();
        } catch (\Throwable $e) {
            error_log("OrderController createFoodOrder: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            Router::$response->json([
                "message" => $e instanceof \DomainException || $e instanceof \InvalidArgumentException ? $e->getMessage() : "Error al crear el pedido",
                "detail" => "No se completó el pedido. No vuelvas a pagar con un método externo."
            ], $e instanceof \DomainException ? 402 : ($e instanceof \InvalidArgumentException ? 422 : 500));
        }
    }

    private function createFoodOrderInternal()
    {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) throw new \InvalidArgumentException('Solicitud inválida');

        // Comprador autenticado (opcional) o invitado solo con teléfono
        $user = Router::$request->user ?? null;
        $userId = $user ? ($user->id ?? null) : null;
        $guestEmail = trim((string)($body['guest_email'] ?? ''));
        $restaurantId = (int)($body['restaurantId'] ?? 0);
        $items = $body['items'] ?? [];
        $total = 0.0;
        $deliveryPhone = trim((string)($body['delivery_phone'] ?? ''));

        if (!$userId) { Router::$response->json(['message' => 'Inicia sesión para pagar con tu Wallet.'], 401); return; }
        $requestKey = (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
        $intent = $body;
        $completed = (new \App\Services\WalletCheckout())->completed((int) $userId, 'food', $requestKey, $intent);
        if ($completed !== null) { Router::$response->json($completed, 200); return; }
        if (!$restaurantId || !is_array($items) || empty($items) || count($items) > 100) {
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

        $requestKey = (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
        $intent = $body;
        $result = (new \App\Services\WalletCheckout())->run((int) $userId, 'food', $requestKey, $intent, function () use ($userId, $restaurantId, $items, $isDelivery, $body, $deliveryPhone, $orderType, $reservationAt, $guestEmail) {
        $db = \App\Configs\Database::getInstance()->getConnection();
        $stmt = $db->prepare('SELECT * FROM restaurantes WHERE id = ? FOR UPDATE');
        $stmt->execute([$restaurantId]);$restaurant = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$restaurant) {
            throw new \DomainException('Restaurante no encontrado');
        }

        $normalizedItems = [];
        $totalCents = 0;
        foreach ($items as $item) {
            $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
            $stmt = $db->prepare('SELECT * FROM platos WHERE restaurant_id = ? AND id = ? AND activo = 1 FOR UPDATE');
            $stmt->execute([$restaurantId, (int) ($item['id'] ?? 0)]);$dish = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$dish || empty($dish['disponible']) || !$quantity || $quantity < 1 || $quantity > 100) {
                throw new \DomainException('Un plato no está disponible o la cantidad no es válida.');
            }
            $priceCents = \App\Services\UsdMoney::cents($dish['precio']);
            $totalCents += $priceCents * $quantity;
            $normalizedItems[] = ['id' => (int) $dish['id'], 'name' => $dish['nombre'], 'price' => (float) \App\Services\UsdMoney::decimal($priceCents), 'quantity' => $quantity];
        }
        $items = $normalizedItems;
        $total = (float) \App\Services\UsdMoney::decimal($totalCents);
        if (\App\Services\UsdMoney::cents($body['total'] ?? null) !== $totalCents) {
            throw new \DomainException('El precio del menú cambió. Actualiza el carrito antes de confirmar.');
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


            if (!$orderId) throw new \RuntimeException('No se pudo crear el pedido');
            $debit = (new WalletModel())->debitForPurchase((int) $userId, $total, 'food_order_' . $orderId);
            if (!$debit['success']) throw new \DomainException($debit['message']);
            (new \App\Services\CommerceSettlement())->hold('food_order_' . $orderId, $debit['transaction_id'], (int) $restaurant['user_id']);
            if (!$this->orderModel->markAsPaid($orderId)) throw new \RuntimeException('No se pudo registrar el pago');
            $db = \App\Configs\Database::getInstance()->getConnection();
            $stmt = $db->prepare('INSERT INTO food_wallet_payments (order_id, user_id, amount) VALUES (?, ?, ?)');
            $stmt->execute([$orderId, $userId, $total]);
            return ['orderId' => $orderId, 'paid_via_wallet' => true, 'new_balance' => $debit['new_balance'], 'total' => $total, 'currency' => 'USD'];
        });
        if (empty($result['replayed'])) {
            try { (new ReceiptModel())->createAndNotify((int) $userId, 'food_order', $result['orderId'], $result['total'], 'wallet', 'Pedido de comida #' . $result['orderId']); }
            catch (\Throwable $e) { error_log('Food receipt pending: ' . $e->getMessage()); }
        }
        Router::$response->json($result + ['message' => 'Pedido pagado con Wallet y enviado al restaurante.'], 201);
    }

    /**
     * Crea un payment link de Square (quick_pay) para un food order.
     * @return array{url:?string,id:?string,error:?string}
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
     * Confirma un pedido ya pagado sin generar otro cobro.
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
                "detail" => 'No se pudo completar la operación'
            ], 500);
        }
    }

    private function confirmOrderInternal($orderId)
    {
        $orderId = (int) $orderId;
        $userId = (int) (Router::$request->user->id ?? 0);
        if (!$userId || !$this->orderModel->belongsToRestaurantOwner($orderId, $userId)) {
            Router::$response->json(['message' => 'No tienes permisos.'], 403); return;
        }
        $order = $this->orderModel->getById($orderId);
        if (!$order || !in_array($order['status'], ['paid', 'confirmed'], true)) {
            Router::$response->json(['message' => 'El pedido todavía no tiene un pago confirmado.'], 409); return;
        }
        if ($order['status'] === 'confirmed') {
            Router::$response->json(['message' => 'El pedido ya estaba confirmado.', 'data' => $order], 200); return;
        }
        if (!$this->orderModel->confirmOrder($orderId, (int) $order['restaurant_id'])) {
            Router::$response->json(['message' => 'El estado cambió. Actualiza el pedido.'], 409); return;
        }
        if (($order['order_type'] ?? '') === 'delivery') $this->dispatchDeliveryDrivers($orderId, $order);
        Router::$response->json(['message' => 'Pedido confirmado. No se realizó un nuevo cobro.', 'data' => $this->orderModel->getById($orderId)], 200);
    }

/**
 * Reintenta el cobro por wallet de un pedido confirmado (el comprador recargo y quiere reintentar).
 */
public function retryWalletPayment($orderId)
{
    $userId = (int) (Router::$request->user->id ?? 0);
    if (!$userId) { Router::$response->json(['message' => 'No autenticado'], 401); return; }
    try {
        $result = (new \App\Services\WalletCheckout())->run($userId, 'food-existing', (string) (int) $orderId, ['order' => (int) $orderId], function () use ($orderId, $userId) {
            $db = \App\Configs\Database::getInstance()->getConnection();
            $stmt = $db->prepare('SELECT * FROM food_orders WHERE id = ? AND user_id = ? FOR UPDATE');
            $stmt->execute([(int) $orderId, $userId]);
            $order = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$order) throw new \DomainException('Pedido no encontrado.');
            $stmt = $db->prepare('SELECT order_id FROM food_wallet_payments WHERE order_id = ?');
            $stmt->execute([(int) $orderId]);
            if ($stmt->fetchColumn() || $order['status'] === 'paid') return ['alreadyPaid' => true, 'message' => 'El pedido ya fue pagado.'];
            if ($order['status'] !== 'confirmed' || $order['currency'] !== 'USD') throw new \DomainException('Este pedido requiere revisión antes de pagarlo.');
            if (!empty($order['payment_link_url']) || !empty($order['square_payment_link_id'])) throw new \DomainException('Pedido con pago externo anterior: requiere conciliación, no se cobrará nuevamente.');
            $amount = (float) \App\Services\UsdMoney::decimal(\App\Services\UsdMoney::cents($order['total']));
            $debit = (new WalletModel())->debitForPurchase($userId, $amount, 'food_order_' . (int) $orderId);
            if (!$debit['success']) throw new \DomainException($debit['message']);
            $restaurant = $this->restaurantModel->getRestaurantById((int) $order['restaurant_id']);
            if (!$restaurant) throw new \RuntimeException('Restaurante no disponible');
            (new \App\Services\CommerceSettlement())->hold('food_order_' . (int) $orderId, $debit['transaction_id'], (int) $restaurant['user_id']);
            if (!$this->orderModel->markAsPaid((int) $orderId)) throw new \RuntimeException('No se pudo registrar el pago.');
            $stmt = $db->prepare('INSERT INTO food_wallet_payments (order_id, user_id, amount) VALUES (?, ?, ?)');
            $stmt->execute([(int) $orderId, $userId, $amount]);
            return ['message' => 'Pago realizado con Wallet.', 'new_balance' => $debit['new_balance']];
        });
        Router::$response->json($result, 200);
    } catch (\Throwable $e) {
        error_log('Food retry failed: ' . $e->getMessage());
        Router::$response->json(['message' => $e instanceof \DomainException ? $e->getMessage() : 'No se completó el pago. Puedes reintentar.'], $e instanceof \DomainException ? 409 : 503);
    }
}

public function getMyPendingPayment()
{
    $userId = Router::$request->user->id ?? null;
    if (!$userId) {
        Router::$response->json(["message" => "No autenticado"], 401);
        return;
    }
    $orders = $this->orderModel->getPendingPaymentForUser((int) $userId);
    Router::$response->json(["data" => $orders], 200);
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
    $body .= "Total: \${$total} " . ($order['currency'] ?? 'USD') . "\n\n";
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
    $body .= "Total: \${$total} " . ($order['currency'] ?? 'USD') . "\n\n";
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

private function sendPaymentEmailWalletPaid(string $to, array $order): bool
{
    $this->paymentLog("sendPaymentEmailWalletPaid TO", $to);

    $subject = "Tuani Eats - Pedido #{$order['id']} confirmado y pagado";
    $total = number_format((float)($order['total'] ?? 0), 2);

    $body = "Hola,\n\n";
    $body .= "Tu pedido #{$order['id']} ha sido confirmado por el restaurante y ya lo pagamos con tu wallet.\n\n";
    $body .= "Total cobrado: \${$total}\n\n";
    $body .= "Gracias por usar Tuani Eats.";

    return $this->sendEmail($to, $subject, $body);
}

private function sendInsufficientWalletEmail(string $to, array $order, ?string $reason = null): bool
{
    $this->paymentLog("sendInsufficientWalletEmail TO", $to);

    $subject = "Tuani Eats - Tu pedido #{$order['id']} necesita saldo en el wallet";
    $total = number_format((float)($order['total'] ?? 0), 2);

    $body = "Hola,\n\n";
    $body .= "Tu pedido #{$order['id']} fue confirmado por el restaurante, pero no pudimos cobrarlo de tu wallet";
    $body .= $reason ? " ({$reason}).\n\n" : ".\n\n";
    $body .= "Total a pagar: \${$total}\n\n";
    $body .= "Recargá tu wallet en la app y reintentá el pago desde la sección de pedidos pendientes en Eats.\n\n";
    $body .= "Gracias por usar Tuani Eats.";

    return $this->sendEmail($to, $subject, $body);
}

/**
 * Despacha el pedido a conductores cercanos al restaurante (envio local).
 * No bloquea la confirmacion si falla -- solo se loguea.
 */
private function dispatchDeliveryDrivers(int $orderId, array $order): void
{
    try {
        $restaurant = $this->restaurantModel->getRestaurantById((int) $order['restaurant_id']);
        if (!$restaurant || empty($restaurant['lat']) || empty($restaurant['lng'])) {
            $this->paymentLog("dispatchDeliveryDrivers: restaurante sin lat/lng, no se despacha");
            return;
        }

        $dispatch = new DeliveryDispatchService();
        $result = $dispatch->dispatchToNearbyDrivers(
            (float) $restaurant['lat'],
            (float) $restaurant['lng'],
            (string) ($restaurant['ubicacion'] ?? ''),
            null,
            null,
            (string) ($order['delivery_address'] ?? ''),
            (float) ($order['total'] ?? 0),
            'comida',
            'eats',
            $orderId
        );

        $this->orderModel->updateDeliveryStatus($orderId, 'searching_driver');
        $this->paymentLog("dispatchDeliveryDrivers RESULT", $result);
    } catch (\Throwable $e) {
        $this->paymentLog("dispatchDeliveryDrivers ERROR", $e->getMessage());
    }
}

}
