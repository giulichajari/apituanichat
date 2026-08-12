<?php
namespace App\Models;
use App\Configs\Database;
use PDO;

class VerificationPaymentModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function create(int $userId, float $amount, string $currency, string $paymentLinkId, string $paymentLinkUrl, string $idempotencyKey): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO verification_payments
                (user_id, amount, currency, payment_link_id, payment_link_url, idempotency_key, status)
            VALUES
                (:user_id, :amount, :currency, :payment_link_id, :payment_link_url, :idempotency_key, 'pending')
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':amount' => $amount,
            ':currency' => $currency,
            ':payment_link_id' => $paymentLinkId,
            ':payment_link_url' => $paymentLinkUrl,
            ':idempotency_key' => $idempotencyKey,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function hasCompletedPayment(int $userId): bool
    {
        $stmt = $this->db->prepare("
            SELECT id FROM verification_payments
            WHERE user_id = :user_id AND status = 'completed'
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getLatestForUser(int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM verification_payments
            WHERE user_id = :user_id
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getByPaymentLinkId(string $paymentLinkId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM verification_payments WHERE payment_link_id = :id LIMIT 1
        ");
        $stmt->execute([':id' => $paymentLinkId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function markAsCompleted(int $id): bool
    {
        $stmt = $this->db->prepare("
            UPDATE verification_payments SET status = 'completed' WHERE id = :id
        ");
        return $stmt->execute([':id' => $id]);
    }
}
