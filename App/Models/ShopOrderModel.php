<?php

namespace App\Models;

use App\Configs\Database;
use PDO;
use PDOException;

class ShopOrderModel
{
    private PDO $db;
    private WalletModel $walletModel;

    public function __construct(?WalletModel $walletModel = null)
    {
        $this->db = Database::getInstance()->getConnection();
        $this->walletModel = $walletModel ?? new WalletModel();
    }

    // Compra un producto: valida disponibilidad, descuenta stock de forma
    // atomica, cobra por wallet (recalculando el total server-side desde el
    // precio real del producto, nunca confiando en un monto del cliente), y
    // registra el pedido. Si el cobro falla despues de descontar stock, el
    // stock se repone.
    public function checkout(
        int $buyerId,
        int $productId,
        int $quantity,
        string $envioTipo = 'local',
        ?string $deliveryAddress = null,
        ?float $deliveryLat = null,
        ?float $deliveryLng = null
    ): array
    {
        if ($quantity <= 0) {
            return ['success' => false, 'message' => 'Cantidad inválida'];
        }

        try {
            $stmt = $this->db->prepare("
                SELECT id, price, stock_quantity, seller_id, is_active, is_approved
                FROM products
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                return ['success' => false, 'message' => 'Producto no encontrado'];
            }
            if (!$product['is_active'] || !$product['is_approved']) {
                return ['success' => false, 'message' => 'Este producto no está disponible'];
            }
            if ((int) $product['seller_id'] === $buyerId) {
                return ['success' => false, 'message' => 'No podés comprar tu propio producto'];
            }

            // Descuento atomico: la condicion stock_quantity >= :qty en el WHERE
            // hace que esta UPDATE sea segura ante compras simultaneas, sin
            // necesitar una transaccion explicita para este paso.
            $stmtDecrement = $this->db->prepare("
                UPDATE products
                SET stock_quantity = stock_quantity - :qty
                WHERE id = :id AND is_active = 1 AND is_approved = 1 AND stock_quantity >= :qty_check
            ");
            $stmtDecrement->execute([':qty' => $quantity, ':id' => $productId, ':qty_check' => $quantity]);

            if ($stmtDecrement->rowCount() !== 1) {
                return ['success' => false, 'message' => 'Stock insuficiente'];
            }

            $unitPrice = (float) $product['price'];
            $total = round($unitPrice * $quantity, 2);
            $sellerId = (int) $product['seller_id'];
            $reference = 'shop_order_product_' . $productId . '_' . time();

            $paymentResult = $this->walletModel->debitForPurchase($buyerId, $total, $reference);

            if (!$paymentResult['success']) {
                // Reponer el stock que se descuento antes de saber si el cobro iba a funcionar
                $stmtRestore = $this->db->prepare("
                    UPDATE products SET stock_quantity = stock_quantity + :qty WHERE id = :id
                ");
                $stmtRestore->execute([':qty' => $quantity, ':id' => $productId]);

                return ['success' => false, 'message' => $paymentResult['message'] ?? 'Error al procesar el pago'];
            }

            $stmtOrder = $this->db->prepare("
                INSERT INTO shop_orders (
                    product_id, buyer_id, seller_id, quantity, unit_price, total, status,
                    envio_tipo, delivery_address, delivery_lat, delivery_lng, payment_reference
                )
                VALUES (
                    :product_id, :buyer_id, :seller_id, :quantity, :unit_price, :total, 'pendiente_envio',
                    :envio_tipo, :delivery_address, :delivery_lat, :delivery_lng, :payment_reference
                )
            ");
            $stmtOrder->execute([
                ':product_id' => $productId,
                ':buyer_id' => $buyerId,
                ':seller_id' => $sellerId,
                ':quantity' => $quantity,
                ':unit_price' => $unitPrice,
                ':total' => $total,
                ':envio_tipo' => $envioTipo,
                ':delivery_address' => $deliveryAddress,
                ':delivery_lat' => $deliveryLat,
                ':delivery_lng' => $deliveryLng,
                ':payment_reference' => $reference,
            ]);

            if ($stmtOrder->rowCount() !== 1) {
                // El cobro ya se hizo pero el pedido no quedo registrado -- caso raro,
                // requiere resolucion manual de un admin. Se loguea como critico.
                error_log("CRITICO: cobro exitoso (ref=$reference) pero fallo el INSERT en shop_orders para product_id=$productId buyer_id=$buyerId");
                return ['success' => false, 'message' => 'El pago se proceso pero hubo un error registrando el pedido. Contactá a soporte con la referencia: ' . $reference];
            }

            return [
                'success' => true,
                'order_id' => (int) $this->db->lastInsertId(),
                'total' => $total,
                'new_balance' => $paymentResult['new_balance'] ?? null,
            ];
        } catch (PDOException $e) {
            error_log("ShopOrderModel checkout ERROR: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error interno al procesar la compra'];
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
            $stmt = $this->db->prepare("UPDATE shop_orders SET delivery_status = :status WHERE id = :id");
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
