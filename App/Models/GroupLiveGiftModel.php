<?php

namespace App\Models;

use App\Configs\Database;
use PDO;
use PDOException;

class GroupLiveGiftModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function create(int $postId, int $senderUserId, float $amount, string $paymentLinkId, string $paymentLinkUrl, string $idempotencyKey): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO group_live_gifts
                (post_id, sender_user_id, amount, payment_link_id, payment_link_url, idempotency_key, estado)
            VALUES
                (:post_id, :sender_user_id, :amount, :payment_link_id, :payment_link_url, :idempotency_key, 'pendiente')
        ");
        $stmt->execute([
            ':post_id' => $postId,
            ':sender_user_id' => $senderUserId,
            ':amount' => $amount,
            ':payment_link_id' => $paymentLinkId,
            ':payment_link_url' => $paymentLinkUrl,
            ':idempotency_key' => $idempotencyKey,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function getByPaymentLinkId(string $paymentLinkId): ?array
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM group_live_gifts WHERE payment_link_id = :id LIMIT 1");
            $stmt->execute([':id' => $paymentLinkId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }

    public function getById(int $id): ?array
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM group_live_gifts WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }

    // Marca el pago como completado y suma los puntos al host del live (1 dolar = 1 punto)
    public function markAsCompleted(int $id): bool
    {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("SELECT post_id, amount FROM group_live_gifts WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $gift = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$gift) {
                $this->db->rollBack();
                return false;
            }

            $stmt2 = $this->db->prepare("UPDATE group_live_gifts SET estado = 'completado' WHERE id = :id");
            $stmt2->execute([':id' => $id]);

            $stmt3 = $this->db->prepare("SELECT user_id FROM group_posts WHERE id = :post_id");
            $stmt3->execute([':post_id' => $gift['post_id']]);
            $post = $stmt3->fetch(PDO::FETCH_ASSOC);

            if ($post) {
                $points = (int) round((float) $gift['amount']);
                $stmt4 = $this->db->prepare("UPDATE users SET total_points = total_points + :points WHERE id = :user_id");
                $stmt4->execute([':points' => $points, ':user_id' => $post['user_id']]);
            }

            $this->db->commit();
            return true;
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("GroupLiveGiftModel markAsCompleted ERROR: " . $e->getMessage());
            return false;
        }
    }

    // Puntos del live actual (suma de regalos completados de este post)
    public function getLivePoints(int $postId): int
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COALESCE(SUM(amount), 0) as total
                FROM group_live_gifts
                WHERE post_id = :post_id AND estado = 'completado'
            ");
            $stmt->execute([':post_id' => $postId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int) round((float) ($row['total'] ?? 0));
        } catch (PDOException $e) {
            return 0;
        }
    }

    // Puntos acumulados totales del usuario (historico de todos sus lives)
    public function getUserTotalPoints(int $userId): int
    {
        try {
            $stmt = $this->db->prepare("SELECT total_points FROM users WHERE id = :id");
            $stmt->execute([':id' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int) ($row['total_points'] ?? 0);
        } catch (PDOException $e) {
            return 0;
        }
    }

    // Regalos completados nuevos desde el ultimo id visto (para el polling del anfitrion)
    public function getCompletedGiftsSince(int $postId, int $sinceId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT g.id, g.sender_user_id, g.amount, g.sent_at, u.name as sender_name
                FROM group_live_gifts g
                JOIN users u ON u.id = g.sender_user_id
                WHERE g.post_id = :post_id AND g.estado = 'completado' AND g.id > :since_id
                ORDER BY g.id ASC
            ");
            $stmt->execute([':post_id' => $postId, ':since_id' => $sinceId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("getCompletedGiftsSince ERROR: " . $e->getMessage());
            return [];
        }
    }
}
