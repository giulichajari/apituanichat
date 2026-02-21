<?php

namespace App\Models;

use App\Configs\Database;
use PDO;

class OrderModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function create(array $data): ?int
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO food_orders (user_id, restaurant_id, items, total, currency, status, payment_link_url, idempotency_key, is_delivery, delivery_address, delivery_phone)
                VALUES (:user_id, :restaurant_id, :items, :total, :currency, 'pending', :payment_link_url, :idempotency_key, :is_delivery, :delivery_address, :delivery_phone)
            ");
            $ok = $stmt->execute([
                ':user_id' => $data['user_id'],
                ':restaurant_id' => $data['restaurant_id'],
                ':items' => is_string($data['items']) ? $data['items'] : json_encode($data['items']),
                ':total' => $data['total'],
                ':currency' => $data['currency'] ?? 'ARS',
                ':payment_link_url' => $data['payment_link_url'] ?? null,
                ':idempotency_key' => $data['idempotency_key'] ?? null,
                ':is_delivery' => !empty($data['is_delivery']) ? 1 : 0,
                ':delivery_address' => $data['delivery_address'] ?? null,
                ':delivery_phone' => $data['delivery_phone'] ?? null
            ]);
            return $ok ? (int)$this->db->lastInsertId() : null;
        } catch (\PDOException $e) {
            error_log("❌ OrderModel create: " . $e->getMessage());
            throw $e;
        }
    }

    public function getByRestaurant(int $restaurantId): array
    {
        $stmt = $this->db->prepare("
            SELECT o.*, u.name as user_name, u.email as user_email
            FROM food_orders o
            LEFT JOIN users u ON o.user_id = u.id
            WHERE o.restaurant_id = ?
            ORDER BY o.created_at DESC
        ");
        $stmt->execute([$restaurantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT o.*, u.name as user_name, u.email as user_email
            FROM food_orders o
            LEFT JOIN users u ON o.user_id = u.id
            WHERE o.id = ?
        ");
        $stmt->execute([$id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        return $order ?: null;
    }

    public function confirmOrder(int $id, int $restaurantId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE food_orders SET status = 'confirmed' 
            WHERE id = ? AND restaurant_id = ? AND status = 'pending'
        ");
        return $stmt->execute([$id, $restaurantId]);
    }

    public function updatePaymentLink(int $orderId, string $paymentLinkUrl, string $squarePaymentLinkId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE food_orders 
            SET payment_link_url = ?, square_payment_link_id = ? 
            WHERE id = ? AND status = 'confirmed'
        ");
        return $stmt->execute([$paymentLinkUrl, $squarePaymentLinkId, $orderId]);
    }

    public function markAsPaid(int $orderId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE food_orders SET status = 'paid' 
            WHERE id = ? AND status IN ('confirmed', 'pending')
        ");
        return $stmt->execute([$orderId]);
    }

    public function getBySquarePaymentLinkId(string $squarePaymentLinkId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT o.*, u.name as user_name, u.email as user_email
            FROM food_orders o
            LEFT JOIN users u ON o.user_id = u.id
            WHERE o.square_payment_link_id = ?
        ");
        $stmt->execute([$squarePaymentLinkId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        return $order ?: null;
    }

    public function belongsToRestaurantOwner(int $orderId, int $userId): bool
    {
        $stmt = $this->db->prepare("
            SELECT 1 FROM food_orders o
            INNER JOIN restaurantes r ON o.restaurant_id = r.id
            WHERE o.id = ? AND r.user_id = ?
        ");
        $stmt->execute([$orderId, $userId]);
        return $stmt->fetch() !== false;
    }
}
