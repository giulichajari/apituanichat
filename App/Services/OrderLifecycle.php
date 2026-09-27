<?php
namespace App\Services;

use App\Configs\Database;
use PDO;

/** All lifecycle mutations lock settlement, then order, then affected inventory/wallets. */
final class OrderLifecycle
{
    private const TYPES = [
        'shop' => ['shop_orders', 'shop_order_', 'buyer_id', 'seller_id'],
        'food' => ['food_orders', 'food_order_', 'user_id', null],
        'ride' => ['ride_requests', 'ride_', 'user_id', 'driver_id'],
    ];
    public function __construct(private ?PDO $db = null) { $this->db ??= Database::getInstance()->getConnection(); }

    private function order(string $type, int $id): array
    {
        $config = self::TYPES[$type] ?? throw new \InvalidArgumentException('Servicio inválido');
        $stmt = $this->db->prepare("SELECT * FROM {$config[0]} WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new \DomainException('Pedido no encontrado');
    }

    /** Called inside the SAME transaction as the ledger change, including admin resolutions. */
    public function synchronize(array $sale, string $action): void
    {
        if (!$this->db->inTransaction()) throw new \LogicException('La resolución requiere una transacción');
        $type = null;
        foreach (self::TYPES as $candidate => $config) {
            if (preg_match('/^' . preg_quote($config[1], '/') . '([1-9][0-9]*)$/D', $sale['reference'], $match)) {
                $type = $candidate; $id = (int) $match[1]; break;
            }
        }
        // Digital entitlements are handled separately; no physical state is inferred for them.
        if ($type === null) return;
        $order = $this->order($type, $id);
        $stmt = $this->db->prepare('SELECT user_id FROM wallets WHERE id = ?');
        $stmt->execute([$sale['payer_wallet_id']]);
        // Family-funded purchases can legitimately have a payer different from the buyer.
        if (!$stmt->fetchColumn()) throw new \DomainException('Wallet de origen no encontrada');
        $amount = $type === 'ride' ? $order['estimated_fare'] : $order['total'];
        if (UsdMoney::cents($amount) !== (int) $sale['gross_cents']) throw new \DomainException('Importe del pedido incompatible; requiere conciliación');
        if ($type === 'food') {
            $stmt = $this->db->prepare('SELECT user_id FROM restaurantes WHERE id = ?');
            $stmt->execute([$order['restaurant_id']]);
            $seller = (int) $stmt->fetchColumn();
        } else $seller = (int) $order[self::TYPES[$type][3]];
        if ($seller !== (int) $sale['seller_user_id']) throw new \DomainException('Proveedor incompatible; requiere conciliación');
        if ($action === 'release') {
            $delivered = match ($type) {
                'shop' => $order['status'] === 'entregado',
                'food' => $order['delivery_status'] === 'delivered' && in_array($order['status'], ['paid', 'confirmed'], true),
                'ride' => $order['status'] === 'completed',
            };
            if (!$delivered) throw new \DomainException('Confirma primero la entrega o finalización del servicio');
            return;
        }
        if ($type === 'shop') {
            if ($order['status'] !== 'pendiente_envio' || in_array($order['delivery_status'], ['picked_up', 'delivered'], true)) {
                throw new \DomainException('Un pedido enviado requiere un proceso de devolución y revisión');
            }
            $stmt = $this->db->prepare('SELECT stock_quantity FROM products WHERE id = ? FOR UPDATE');
            $stmt->execute([$order['product_id']]);
            if ($stmt->fetchColumn() === false) throw new \DomainException('Producto no encontrado para reponer stock');
            $this->db->prepare('UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?')->execute([$order['quantity'], $order['product_id']]);
            $this->db->prepare("UPDATE shop_orders SET status = 'cancelado', delivery_status = 'cancelled' WHERE id = ?")->execute([$id]);
        } elseif ($type === 'food') {
            if (!in_array($order['status'], ['paid', 'confirmed'], true) || in_array($order['delivery_status'], ['picked_up', 'delivered'], true)) {
                throw new \DomainException('Este pedido requiere revisión antes de devolver el pago');
            }
            $this->db->prepare("UPDATE food_orders SET status = 'cancelled', delivery_status = 'cancelled' WHERE id = ?")->execute([$id]);
            $this->db->prepare("UPDATE delivery_offers SET status = 'expired', responded_at = CURRENT_TIMESTAMP WHERE order_id = ? AND status IN ('sent', 'accepted')")->execute([$id]);
        } else {
            if (!in_array($order['status'], ['pending', 'accepted'], true)) throw new \DomainException('El viaje ya terminó; requiere revisión');
            $this->db->prepare("UPDATE ride_requests SET status = 'rejected' WHERE id = ?")->execute([$id]);
            $this->db->prepare("UPDATE payments SET status = 'refunded' WHERE ride_request_id = ? AND payment_method = 'wallet' AND status = 'completed'")->execute([$id]);
        }
        // Do not automatically put a driver online: they may have deliberately gone offline.
    }

    public function transition(string $type, int $id, string $action, int $userId): array
    {
        $config = self::TYPES[$type] ?? throw new \InvalidArgumentException('Servicio inválido');
        if ($id < 1 || $userId < 1 || !in_array($action, ['cancel', 'complete', 'accept', 'reject'], true)) throw new \InvalidArgumentException('Acción inválida');
        $reference = $config[1] . $id;
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM commerce_settlements WHERE reference = ? FOR UPDATE');
            $stmt->execute([$reference]);
            $sale = $stmt->fetch(PDO::FETCH_ASSOC);
            $order = $this->order($type, $id);
            $buyer = (int) $order[$config[2]] === $userId;
            $driver = $type === 'ride' && (int) $order['driver_id'] === $userId;
            if (($action === 'cancel' || $action === 'complete') ? !$buyer : !$driver) throw new \DomainException('No tienes permiso para esta acción');
            if (!$sale) throw new \DomainException('Pedido anterior a esta integración: requiere revisión del pago');
            if ($action === 'accept') {
                if ($sale['status'] !== 'held' || !in_array($order['status'], ['pending', 'accepted'], true)) throw new \DomainException('El viaje ya no está disponible');
                $this->db->prepare("UPDATE ride_requests SET status = 'accepted' WHERE id = ?")->execute([$id]);
                $result = ['status' => 'accepted', 'replayed' => $order['status'] === 'accepted'];
            } else {
                $resolution = $action === 'complete' ? 'release' : 'refund';
                $target = $resolution === 'release' ? 'released' : 'refunded';
                if ($sale['status'] !== $target) {
                    if ($sale['status'] !== 'held') throw new \DomainException('El pedido ya tiene otra resolución');
                    if ($action === 'complete') {
                        if ($type === 'shop') {
                            if ($order['status'] !== 'enviado') throw new \DomainException('El pedido aún no fue enviado');
                            $this->db->prepare("UPDATE shop_orders SET status = 'entregado', delivery_status = 'delivered' WHERE id = ?")->execute([$id]);
                        } elseif ($type === 'food') {
                            if ($order['status'] !== 'confirmed' || $order['delivery_status'] === 'cancelled') throw new \DomainException('El pedido aún no está listo para confirmar recepción');
                            $this->db->prepare("UPDATE food_orders SET delivery_status = 'delivered', delivered_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$id]);
                        } else {
                            if ($order['status'] !== 'accepted') throw new \DomainException('El viaje no fue aceptado');
                            $this->db->prepare("UPDATE ride_requests SET status = 'completed' WHERE id = ?")->execute([$id]);
                        }
                    } elseif ($action === 'cancel') {
                        $canCancel = match ($type) {
                            'shop' => $order['status'] === 'pendiente_envio' && !in_array($order['delivery_status'], ['assigned', 'picked_up', 'delivered'], true),
                            'food' => $order['status'] === 'paid' && empty($order['driver_id']),
                            'ride' => $order['status'] === 'pending',
                        };
                        if (!$canCancel) throw new \DomainException('El servicio ya está en curso; solicita revisión a soporte');
                    } elseif ($order['status'] !== 'pending') throw new \DomainException('Solo puedes rechazar solicitudes pendientes');
                }
                $result = (new CommerceSettlement($this->db))->resolve($reference, $resolution, $userId, 'Usuario: ' . $action);
            }
            if ($type === 'ride') RidePush::enqueue($this->db,$id);
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
