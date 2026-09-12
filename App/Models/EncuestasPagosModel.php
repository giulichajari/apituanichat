<?php

namespace App\Models;

use App\Configs\Database;
use PDO;
use PDOException;

class EncuestasPagosModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function createPaymentRequest(
        int $encuestaId,
        int $usuarioId,
        float $monto,
        string $paymentLinkId,
        string $paymentLinkUrl,
        string $idempotencyKey
    ): int {
        $stmt = $this->db->prepare("
            INSERT INTO encuestas_pagos
                (encuesta_id, usuario_id, monto, currency, payment_link_id, payment_link_url, idempotency_key, estado)
            VALUES
                (:encuesta_id, :usuario_id, :monto, 'USD', :payment_link_id, :payment_link_url, :idempotency_key, 'pendiente')
        ");
        $stmt->execute([
            ':encuesta_id' => $encuestaId,
            ':usuario_id' => $usuarioId,
            ':monto' => $monto,
            ':payment_link_id' => $paymentLinkId,
            ':payment_link_url' => $paymentLinkUrl,
            ':idempotency_key' => $idempotencyKey,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function getByPaymentLinkId(string $paymentLinkId): ?array
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM encuestas_pagos WHERE payment_link_id = :id LIMIT 1");
            $stmt->execute([':id' => $paymentLinkId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            error_log("EncuestasPagosModel getByPaymentLinkId ERROR: " . $e->getMessage());
            return null;
        }
    }

    public function markCompleted(int $id, ?string $squarePaymentId = null): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE encuestas_pagos
                SET estado = 'completado', square_payment_id = :square_payment_id
                WHERE id = :id
            ");
            return $stmt->execute([
                ':id' => $id,
                ':square_payment_id' => $squarePaymentId,
            ]);
        } catch (PDOException $e) {
            error_log("EncuestasPagosModel markCompleted ERROR: " . $e->getMessage());
            return false;
        }
    }

    public function getPendingForEncuestaAndUser(int $encuestaId, int $usuarioId): ?array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM encuestas_pagos
                WHERE encuesta_id = :encuesta_id AND usuario_id = :usuario_id AND estado = 'pendiente'
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([':encuesta_id' => $encuestaId, ':usuario_id' => $usuarioId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            error_log("EncuestasPagosModel getPendingForEncuestaAndUser ERROR: " . $e->getMessage());
            return null;
        }
    }
}
