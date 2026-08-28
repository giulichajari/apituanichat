<?php

namespace App\Models;

use App\Configs\Database;
use PDO;
use PDOException;

class GroupLiveChatModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function createFreeMessage(int $postId, int $senderUserId, string $message): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO group_live_chat_messages
                (post_id, sender_user_id, message, is_pinned, estado)
            VALUES
                (:post_id, :sender_user_id, :message, 0, 'completado')
        ");
        $stmt->execute([
            ':post_id' => $postId,
            ':sender_user_id' => $senderUserId,
            ':message' => $message,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function createPinRequest(
        int $postId,
        int $senderUserId,
        string $message,
        float $amount,
        int $minutes,
        string $paymentLinkId,
        string $paymentLinkUrl,
        string $idempotencyKey
    ): int {
        $stmt = $this->db->prepare("
            INSERT INTO group_live_chat_messages
                (post_id, sender_user_id, message, is_pinned, pin_amount, pin_minutes,
                 payment_link_id, payment_link_url, idempotency_key, estado)
            VALUES
                (:post_id, :sender_user_id, :message, 1, :amount, :minutes,
                 :payment_link_id, :payment_link_url, :idempotency_key, 'pendiente')
        ");
        $stmt->execute([
            ':post_id' => $postId,
            ':sender_user_id' => $senderUserId,
            ':message' => $message,
            ':amount' => $amount,
            ':minutes' => $minutes,
            ':payment_link_id' => $paymentLinkId,
            ':payment_link_url' => $paymentLinkUrl,
            ':idempotency_key' => $idempotencyKey,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function getByPaymentLinkId(string $paymentLinkId): ?array
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM group_live_chat_messages WHERE payment_link_id = :id LIMIT 1");
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
            $stmt = $this->db->prepare("SELECT * FROM group_live_chat_messages WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }

    public function markPinCompleted(int $id): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE group_live_chat_messages
                SET estado = 'completado',
                    pinned_until = DATE_ADD(NOW(), INTERVAL pin_minutes MINUTE)
                WHERE id = :id
            ");
            return $stmt->execute([':id' => $id]);
        } catch (PDOException $e) {
            error_log("GroupLiveChatModel markPinCompleted ERROR: " . $e->getMessage());
            return false;
        }
    }

    public function getMessagesSince(int $postId, int $sinceId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT m.id, m.sender_user_id, m.message, m.created_at, u.name as sender_name
                FROM group_live_chat_messages m
                JOIN users u ON u.id = m.sender_user_id
                WHERE m.post_id = :post_id AND m.is_pinned = 0 AND m.estado = 'completado' AND m.id > :since_id
                ORDER BY m.id ASC
            ");
            $stmt->execute([':post_id' => $postId, ':since_id' => $sinceId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("getMessagesSince ERROR: " . $e->getMessage());
            return [];
        }
    }

    public function getActivePinned(int $postId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT m.id, m.sender_user_id, m.message, m.pin_amount, m.pin_minutes,
                       m.pinned_until, m.created_at, u.name as sender_name
                FROM group_live_chat_messages m
                JOIN users u ON u.id = m.sender_user_id
                WHERE m.post_id = :post_id AND m.is_pinned = 1 AND m.estado = 'completado'
                      AND m.pinned_until > NOW()
                ORDER BY m.pin_amount DESC, m.pinned_until ASC
            ");
            $stmt->execute([':post_id' => $postId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("getActivePinned ERROR: " . $e->getMessage());
            return [];
        }
    }
}
