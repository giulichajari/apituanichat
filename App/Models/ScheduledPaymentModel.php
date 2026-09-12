<?php

namespace App\Models;

use App\Configs\Database;
use PDO;
use PDOException;

class ScheduledPaymentModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function create(int $userId, string $serviceType, ?string $descripcion, float $amount, string $executionDate): int|false
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO scheduled_payments (user_id, service_type, descripcion, amount, execution_date)
                VALUES (:user_id, :service_type, :descripcion, :amount, :execution_date)
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':service_type' => $serviceType,
                ':descripcion' => $descripcion,
                ':amount' => $amount,
                ':execution_date' => $executionDate,
            ]);
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log("ScheduledPaymentModel create ERROR: " . $e->getMessage());
            return false;
        }
    }

    public function getByUser(int $userId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM scheduled_payments
                WHERE user_id = :user_id
                ORDER BY execution_date ASC
            ");
            $stmt->execute([':user_id' => $userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("ScheduledPaymentModel getByUser ERROR: " . $e->getMessage());
            return [];
        }
    }

    public function cancel(int $id, int $userId): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE scheduled_payments
                SET status = 'cancelled'
                WHERE id = :id AND user_id = :user_id AND status = 'pending'
            ");
            $stmt->execute([':id' => $id, ':user_id' => $userId]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("ScheduledPaymentModel cancel ERROR: " . $e->getMessage());
            return false;
        }
    }

    // Usado solo por el script de cron: pagos pendientes cuya fecha ya llego
    public function getDueForToday(): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM scheduled_payments
                WHERE status = 'pending' AND execution_date <= CURDATE()
            ");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("ScheduledPaymentModel getDueForToday ERROR: " . $e->getMessage());
            return [];
        }
    }

    public function markCompleted(int $id): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE scheduled_payments SET status = 'completed', processed_at = NOW() WHERE id = :id
            ");
            return $stmt->execute([':id' => $id]);
        } catch (PDOException $e) {
            error_log("ScheduledPaymentModel markCompleted ERROR: " . $e->getMessage());
            return false;
        }
    }

    public function markFailed(int $id): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE scheduled_payments SET status = 'failed', processed_at = NOW() WHERE id = :id
            ");
            return $stmt->execute([':id' => $id]);
        } catch (PDOException $e) {
            error_log("ScheduledPaymentModel markFailed ERROR: " . $e->getMessage());
            return false;
        }
    }
}
