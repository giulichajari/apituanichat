<?php

namespace App\Models;

use App\Configs\Database;
use PDO;

class PaymentModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }
    public function findByUserId(int $userId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM payments WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByDriverId(int $driverId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM payments WHERE driver_id = ? ORDER BY created_at DESC");
        $stmt->execute([$driverId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

  public function create(array $data): ?int
{
    $stmt = $this->db->prepare("
        INSERT INTO payments (ride_request_id, user_id, driver_id, amount, currency, status, payment_link_url, idempotency_key)
        VALUES (:ride_request_id, :user_id, :driver_id, :amount, :currency, :status, :payment_link_url, :idempotency_key)
    ");
    $ok = $stmt->execute([
        ':ride_request_id' => $data['ride_request_id'],
        ':user_id' => $data['user_id'],
        ':driver_id' => $data['driver_id'],
        ':amount' => $data['amount'],
        ':currency' => $data['currency'],
        ':status' => $data['status'] ?? 'pending',
        ':payment_link_url' => $data['payment_link_url'],
        ':idempotency_key' => $data['idempotency_key']
    ]);

    return $ok ? (int)$this->db->lastInsertId() : null;
}


    public function updateStatus(string $idempotencyKey, string $status, ?string $squarePaymentId = null): bool
    {
        $stmt = $this->db->prepare("
            UPDATE payments 
            SET status = :status, square_payment_id = :square_payment_id 
            WHERE idempotency_key = :idempotency_key
        ");
        return $stmt->execute([
            ':status' => $status,
            ':square_payment_id' => $squarePaymentId,
            ':idempotency_key' => $idempotencyKey
        ]);
    }

    public function findByIdempotencyKey(string $key): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM payments WHERE idempotency_key = ?");
        $stmt->execute([$key]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        return $payment ?: null;
    }

    public function findByRideId(int $rideId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM payments WHERE ride_id = ?");
        $stmt->execute([$rideId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        return $payment ?: null;
    }
}
