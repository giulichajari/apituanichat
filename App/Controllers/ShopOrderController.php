<?php

namespace App\Controllers;

use App\Models\ShopOrderModel;
use App\Models\ShopStoreModel;
use App\Services\DeliveryDispatchService;
use App\Services\MailService;
use EasyProjects\SimpleRouter\Router;

class ShopOrderController
{
    private ShopOrderModel $model;
    private ShopStoreModel $storeModel;

    public function __construct(?ShopOrderModel $model = null, ?ShopStoreModel $storeModel = null)
    {
        $this->model = $model ?? new ShopOrderModel();
        $this->storeModel = $storeModel ?? new ShopStoreModel();
    }

    // Compra un producto del Shop, pagando con wallet
    public function checkout()
    {
        $userId = (int) (Router::$request->user->id ?? 0);
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }

        $body = Router::$request->body;
        $productId = (int) ($body->product_id ?? 0);
        $quantity = (int) ($body->quantity ?? 1);
        $envioTipo = trim((string) ($body->envio_tipo ?? 'local'));
        $deliveryAddress = isset($body->delivery_address) ? trim((string) $body->delivery_address) : null;
        $deliveryLat = isset($body->delivery_lat) && $body->delivery_lat !== null ? (float) $body->delivery_lat : null;
        $deliveryLng = isset($body->delivery_lng) && $body->delivery_lng !== null ? (float) $body->delivery_lng : null;

        if (!$productId) {
            Router::$response->status(400)->json(["message" => "Falta product_id"]);
            return;
        }

        if (!in_array($envioTipo, ['local', 'nacional', 'internacional'], true)) {
            Router::$response->status(400)->json(["message" => "envio_tipo invalido"]);
            return;
        }

        if ($envioTipo === 'local' && ($deliveryAddress === null || $deliveryAddress === '' || $deliveryLat === null || $deliveryLng === null)) {
            Router::$response->status(400)->json(["message" => "Para envio local son obligatorios delivery_address, delivery_lat y delivery_lng"]);
            return;
        }

        $result = $this->model->checkout($userId, $productId, $quantity, $envioTipo, $deliveryAddress, $deliveryLat, $deliveryLng);

        if (!$result['success']) {
            Router::$response->status(400)->json(["message" => $result['message']]);
            return;
        }

        $orderId = (int) $result['order_id'];

        if ($envioTipo === 'local') {
            $this->dispatchLocalDelivery($orderId, $result, $deliveryAddress, $deliveryLat, $deliveryLng);
        } else {
            $this->notifyAdminNonLocalOrder($orderId, $envioTipo, $result, $deliveryAddress);
        }

        Router::$response->status(201)->json([
            "message" => "Compra realizada correctamente",
            "order_id" => $orderId,
            "total" => $result['total'],
            "new_balance" => $result['new_balance'],
        ]);
    }

    /**
     * Despacha el pedido local a conductores cercanos a la tienda del vendedor.
     * No bloquea la respuesta del checkout si falla -- solo se loguea.
     */
    private function dispatchLocalDelivery(int $orderId, array $result, ?string $deliveryAddress, ?float $deliveryLat, ?float $deliveryLng): void
    {
        try {
            $orderRow = $this->getOrderRow($orderId);
            if (!$orderRow) {
                return;
            }
            $sellerId = (int) $orderRow['seller_id'];

            $store = $this->storeModel->getStoreByUserId($sellerId);
            if (!$store || empty($store['lat']) || empty($store['lng'])) {
                error_log("ShopOrderController dispatchLocalDelivery: vendedor $sellerId sin tienda con lat/lng, no se despacha");
                return;
            }

            $dispatch = new DeliveryDispatchService();
            $dispatchResult = $dispatch->dispatchToNearbyDrivers(
                (float) $store['lat'],
                (float) $store['lng'],
                (string) ($store['direccion'] ?? $store['store_name'] ?? ''),
                $deliveryLat,
                $deliveryLng,
                (string) ($deliveryAddress ?? ''),
                (float) ($result['total'] ?? 0),
                'paquete',
                'shop',
                $orderId
            );

            $this->model->updateDeliveryStatus($orderId, 'searching_driver');
            error_log("ShopOrderController dispatchLocalDelivery RESULT: " . json_encode($dispatchResult));
        } catch (\Throwable $e) {
            error_log("ShopOrderController dispatchLocalDelivery ERROR: " . $e->getMessage());
        }
    }

    /**
     * Pedidos nacionales/internacionales: no van a conductores locales, se
     * avisa por email a soporte para gestion manual del envio.
     */
    private function notifyAdminNonLocalOrder(int $orderId, string $envioTipo, array $result, ?string $deliveryAddress): void
    {
        try {
            $to = $_ENV['SUPPORT_EMAIL'] ?? 'soporte@tuanichat.com';
            $subject = "Tuani Shop - Nuevo pedido {$envioTipo} #{$orderId} para gestionar";
            $total = number_format((float) ($result['total'] ?? 0), 2);
            $body = "Nuevo pedido de tipo '{$envioTipo}' que requiere gestion manual de envio.\n\n"
                . "Pedido: #{$orderId}\n"
                . "Total: \${$total}\n"
                . "Direccion de entrega: " . ($deliveryAddress ?: 'No especificada') . "\n\n"
                . "Ingresa al panel de admin para coordinar el envio.";
            MailService::send($to, $subject, $body, 'Tuani Shop');
        } catch (\Throwable $e) {
            error_log("ShopOrderController notifyAdminNonLocalOrder ERROR: " . $e->getMessage());
        }
    }

    private function getOrderRow(int $orderId): ?array
    {
        $stmt = \App\Configs\Database::getInstance()->getConnection()->prepare(
            "SELECT * FROM shop_orders WHERE id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $orderId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // Historial de compras del comprador logueado
    public function getMyPurchases()
    {
        $userId = (int) (Router::$request->user->id ?? 0);
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }

        $orders = $this->model->getMyPurchases($userId);

        Router::$response->status(200)->json(["data" => $orders]);
    }

    // Pedidos pendientes de envio del vendedor logueado
    public function getPendingShipments()
    {
        $userId = (int) (Router::$request->user->id ?? 0);
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }

        $orders = $this->model->getPendingShipments($userId);

        Router::$response->status(200)->json(["data" => $orders]);
    }

    // El vendedor marca un pedido propio como enviado
    public function markAsShipped($orderId)
    {
        $userId = (int) (Router::$request->user->id ?? 0);
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }

        $id = (int) $orderId;
        if (!$id) {
            Router::$response->status(400)->json(["message" => "ID de pedido no válido"]);
            return;
        }

        $ok = $this->model->markAsShipped($id, $userId);
        if (!$ok) {
            Router::$response->status(400)->json(["message" => "No se pudo marcar como enviado (verificá que el pedido sea tuyo y esté pendiente)"]);
            return;
        }

        Router::$response->status(200)->json(["message" => "Pedido marcado como enviado"]);
    }

    // Metricas del vendedor logueado para su dashboard
    public function getSellerStats()
    {
        $userId = (int) (Router::$request->user->id ?? 0);
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }

        $stats = $this->model->getSellerStats($userId);

        Router::$response->status(200)->json(["data" => $stats]);
    }
}
