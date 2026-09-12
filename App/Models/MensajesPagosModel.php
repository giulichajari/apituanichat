<?php

namespace App\Models;

use App\Configs\Database;
use PDO;
use PDOException;

class MensajesPagosModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    // Crea una solicitud de pago pendiente para desbloquear un mensaje de grupo
    public function createPaymentRequest(
        int $mensajeId,
        int $usuarioId,
        float $monto,
        string $paymentLinkId,
        string $paymentLinkUrl,
        string $idempotencyKey
    ): int {
        $stmt = $this->db->prepare("
            INSERT INTO mensajes_pagos
                (mensaje_id, usuario_id, monto, currency, payment_link_id, payment_link_url, idempotency_key, estado)
            VALUES
                (:mensaje_id, :usuario_id, :monto, 'USD', :payment_link_id, :payment_link_url, :idempotency_key, 'pendiente')
        ");
        $stmt->execute([
            ':mensaje_id' => $mensajeId,
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
            $stmt = $this->db->prepare("SELECT * FROM mensajes_pagos WHERE payment_link_id = :id LIMIT 1");
            $stmt->execute([':id' => $paymentLinkId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            error_log("MensajesPagosModel getByPaymentLinkId ERROR: " . $e->getMessage());
            return null;
        }
    }

    public function markCompleted(int $id, ?string $squarePaymentId = null): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE mensajes_pagos
                SET estado = 'completado', square_payment_id = :square_payment_id
                WHERE id = :id
            ");
            return $stmt->execute([
                ':id' => $id,
                ':square_payment_id' => $squarePaymentId,
            ]);
        } catch (PDOException $e) {
            error_log("MensajesPagosModel markCompleted ERROR: " . $e->getMessage());
            return false;
        }
    }

    // Crea un pago ya completado directamente (pago con wallet, sin Square)
    public function createWalletPayment(int $mensajeId, int $usuarioId, float $monto): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO mensajes_pagos
                (mensaje_id, usuario_id, monto, currency, estado, payment_method)
            VALUES
                (:mensaje_id, :usuario_id, :monto, 'USD', 'completado', 'wallet')
        ");
        $stmt->execute([
            ':mensaje_id' => $mensajeId,
            ':usuario_id' => $usuarioId,
            ':monto' => $monto,
        ]);
        return (int) $this->db->lastInsertId();
    }

    // Evita crear multiples pagos pendientes duplicados para el mismo mensaje+usuario
    public function getPendingForMessageAndUser(int $mensajeId, int $usuarioId): ?array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM mensajes_pagos
                WHERE mensaje_id = :mensaje_id AND usuario_id = :usuario_id AND estado = 'pendiente'
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([':mensaje_id' => $mensajeId, ':usuario_id' => $usuarioId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            error_log("MensajesPagosModel getPendingForMessageAndUser ERROR: " . $e->getMessage());
            return null;
        }
    }
}
