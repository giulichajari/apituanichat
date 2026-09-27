<?php

namespace App\Models;

use App\Configs\Database;
use PDO;
use PDOException;

class ShopOrderModel
{
    private PDO $db;
    private WalletModel $walletModel;

    public function __construct(?WalletModel $walletModel = null, ?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->walletModel = $walletModel ?? new WalletModel($this->db);
    }

    // Compra un producto: valida disponibilidad, descuenta stock de forma
    // atomica, cobra por wallet (recalculando el total server-side desde el
    // precio real del producto, nunca confiando en un monto del cliente), y
    // registra el pedido. Si el cobro falla despues de descontar stock, el
    // stock se repone.
    public function checkout(int $buyerId, int $productId, int $quantity, string $envioTipo = 'local', ?string $deliveryAddress = null, ?float $deliveryLat = null, ?float $deliveryLng = null, string $requestKey = ''): array
    {
        if ($quantity < 1 || $quantity > 100) return ['success' => false, 'message' => 'Cantidad inválida'];
        try {
            return (new \App\Services\WalletCheckout($this->db))->run($buyerId, 'shop', $requestKey, compact('productId', 'quantity', 'envioTipo', 'deliveryAddress', 'deliveryLat', 'deliveryLng'), function () use ($buyerId, $productId, $quantity, $envioTipo, $deliveryAddress, $deliveryLat, $deliveryLng, $requestKey) {
                $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
                $stmt->execute([$productId]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$product || !$product['is_active'] || !$product['is_approved'] || (int) $product['seller_id'] === $buyerId) throw new \DomainException('Producto no disponible');
                if ((int) $product['stock_quantity'] < $quantity) throw new \DomainException('Stock insuficiente');
                $total = \App\Services\UsdMoney::decimal(\App\Services\UsdMoney::cents($product['price']) * $quantity);
                $reference = 'shop_' . $buyerId . '_' . $requestKey;
                $debit = $this->walletModel->debitForPurchase($buyerId, (float) $total, $reference);
                if (!$debit['success']) throw new \DomainException($debit['message']);
                $stmt = $this->db->prepare('UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?');
                $stmt->execute([$quantity, $productId]);
                $stmt = $this->db->prepare("INSERT INTO shop_orders (product_id, buyer_id, seller_id, quantity, unit_price, total, status, envio_tipo, delivery_address, delivery_lat, delivery_lng, payment_reference) VALUES (?, ?, ?, ?, ?, ?, 'pendiente_envio', ?, ?, ?, ?, ?)");
                $stmt->execute([$productId, $buyerId, $product['seller_id'], $quantity, $product['price'], $total, $envioTipo, $deliveryAddress, $deliveryLat, $deliveryLng, $reference]);
                $orderId = (int) $this->db->lastInsertId();
                (new \App\Services\CommerceSettlement($this->db))->hold('shop_order_' . $orderId, $debit['transaction_id'], (int) $product['seller_id']);
                return ['success' => true, 'order_id' => $orderId, 'total' => $total, 'new_balance' => $debit['new_balance']];
            });
        } catch (\Throwable $e) {
            error_log('Shop checkout: ' . $e->getMessage());
            return ['success' => false, 'message' => $e instanceof \DomainException || $e instanceof \InvalidArgumentException ? $e->getMessage() : 'No se completó la compra. Puedes reintentar.'];
        }
    }

    // Actualiza el estado de despacho del delivery local (busqueda/asignacion de conductor)
    public function updateDeliveryStatus(int $orderId, string $status): bool
    {
        $allowed = ['not_applicable', 'searching_driver', 'assigned', 'picked_up', 'delivered', 'cancelled'];
        if (!in_array($status, $allowed, true)) {
            return false;
        }
        try {
            $stmt = $this->db->prepare("UPDATE shop_orders SET delivery_status = :status WHERE id = :id AND status NOT IN ('cancelado', 'entregado')");
            return $stmt->execute([':status' => $status, ':id' => $orderId]);
        } catch (PDOException $e) {
            error_log("ShopOrderModel updateDeliveryStatus ERROR: " . $e->getMessage());
            return false;
        }
    }

    // Historial de compras del comprador logueado, con el estado de cada pedido
    public function getMyPurchases(int $buyerId): array
    {
        $stmt = $this->db->prepare("
            SELECT o.*, p.name AS product_name, p.image_url AS product_image, u.name AS seller_name
            FROM shop_orders o
            JOIN products p ON o.product_id = p.id
            LEFT JOIN users u ON o.seller_id = u.id
            WHERE o.buyer_id = :buyer_id
            ORDER BY o.created_at DESC
        ");
        $stmt->execute([':buyer_id' => $buyerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Pedidos pendientes de envio del vendedor logueado (mas nuevos primero)
    public function getPendingShipments(int $sellerId): array
    {
        $stmt = $this->db->prepare("
            SELECT o.*, p.name AS product_name, p.image_url AS product_image, u.name AS buyer_name
            FROM shop_orders o
            JOIN products p ON o.product_id = p.id
            LEFT JOIN users u ON o.buyer_id = u.id
            WHERE o.seller_id = :seller_id AND o.status = 'pendiente_envio'
            ORDER BY o.created_at DESC
        ");
        $stmt->execute([':seller_id' => $sellerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // El vendedor marca un pedido propio como enviado
    public function markAsShipped(int $orderId, int $sellerId): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE shop_orders
                SET status = 'enviado'
                WHERE id = :id AND seller_id = :seller_id AND status = 'pendiente_envio'
            ");
            $stmt->execute([':id' => $orderId, ':seller_id' => $sellerId]);
            return $stmt->rowCount() === 1;
        } catch (PDOException $e) {
            error_log("ShopOrderModel markAsShipped ERROR: " . $e->getMessage());
            return false;
        }
    }

    // Metricas del vendedor logueado: total vendido (historico, solo pedidos no
    // cancelados), vendido este mes (dinero y unidades), y el producto mas
    // vendido historicamente por unidades.
    public function getSellerStats(int $sellerId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN status != 'cancelado' THEN total ELSE 0 END), 0) AS total_vendido,
                COALESCE(SUM(CASE WHEN status != 'cancelado' THEN quantity ELSE 0 END), 0) AS unidades_vendidas_total,
                COALESCE(SUM(CASE
                    WHEN status != 'cancelado' AND YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())
                    THEN total ELSE 0 END), 0) AS vendido_este_mes,
                COALESCE(SUM(CASE
                    WHEN status != 'cancelado' AND YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())
                    THEN quantity ELSE 0 END), 0) AS unidades_vendidas_este_mes
            FROM shop_orders
            WHERE seller_id = :seller_id
        ");
        $stmt->execute([':seller_id' => $sellerId]);
        $totals = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $stmtTop = $this->db->prepare("
            SELECT p.id, p.name, SUM(o.quantity) AS unidades_vendidas
            FROM shop_orders o
            JOIN products p ON o.product_id = p.id
            WHERE o.seller_id = :seller_id AND o.status != 'cancelado'
            GROUP BY p.id, p.name
            ORDER BY unidades_vendidas DESC
            LIMIT 1
        ");
        $stmtTop->execute([':seller_id' => $sellerId]);
        $topProduct = $stmtTop->fetch(PDO::FETCH_ASSOC) ?: null;

        // Productos publicados y listos para vender ahora mismo (activos, aprobados, con stock)
        $stmtListings = $this->db->prepare("
            SELECT COUNT(*) FROM products
            WHERE seller_id = :seller_id AND is_active = 1 AND is_approved = 1 AND stock_quantity > 0
        ");
        $stmtListings->execute([':seller_id' => $sellerId]);
        $productosListos = (int) $stmtListings->fetchColumn();

        return [
            'total_vendido' => (float) ($totals['total_vendido'] ?? 0),
            'unidades_vendidas_total' => (int) ($totals['unidades_vendidas_total'] ?? 0),
            'vendido_este_mes' => (float) ($totals['vendido_este_mes'] ?? 0),
            'unidades_vendidas_este_mes' => (int) ($totals['unidades_vendidas_este_mes'] ?? 0),
            'producto_mas_vendido' => $topProduct,
            'productos_listos_para_vender' => $productosListos,
        ];
    }
}
