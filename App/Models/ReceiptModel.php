<?php

namespace App\Models;

use App\Configs\Database;
use App\Services\MailService;
use PDO;
use PDOException;

class ReceiptModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    // Guarda el recibo y ademas manda el email de confirmacion.
    public function createAndNotify(
        int $userId,
        string $type,
        int $referenceId,
        float $amount,
        string $paymentMethod,
        string $description,
        ?string $squarePaymentId = null
    ): bool {
        $ok = $this->create($userId, $type, $referenceId, $amount, $paymentMethod, $description, $squarePaymentId);
        if ($ok) {
            $this->emailReceipt($userId, $amount, $description, $paymentMethod);
        }
        return $ok;
    }

    public function create(
        int $userId,
        string $type,
        int $referenceId,
        float $amount,
        string $paymentMethod,
        string $description,
        ?string $squarePaymentId = null
    ): bool {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO purchase_receipts
                    (user_id, type, reference_id, amount, currency, payment_method, description, square_payment_id)
                VALUES
                    (:user_id, :type, :reference_id, :amount, 'USD', :payment_method, :description, :square_payment_id)
            ");
            return $stmt->execute([
                ':user_id' => $userId,
                ':type' => $type,
                ':reference_id' => $referenceId,
                ':amount' => $amount,
                ':payment_method' => $paymentMethod,
                ':description' => $description,
                ':square_payment_id' => $squarePaymentId,
            ]);
        } catch (PDOException $e) {
            error_log("ReceiptModel create ERROR: " . $e->getMessage());
            return false;
        }
    }

    public function getForUser(int $userId, int $page = 1, int $perPage = 20): array
    {
        try {
            $offset = ($page - 1) * $perPage;
            $stmt = $this->db->prepare("
                SELECT * FROM purchase_receipts
                WHERE user_id = :user_id
                ORDER BY created_at DESC
                LIMIT :limit OFFSET :offset
            ");
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("ReceiptModel getForUser ERROR: " . $e->getMessage());
            return [];
        }
    }

    private function emailReceipt(int $userId, float $amount, string $description, string $paymentMethod): void
    {
        try {
            $stmt = $this->db->prepare("SELECT email, name FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user || empty($user['email'])) {
                return;
            }
            $metodo = $paymentMethod === 'wallet' ? 'Wallet' : 'Tarjeta';
            $subject = 'Recibo de tu compra en TuaniChat';
            $body = "Hola " . ($user['name'] ?? '') . ",\n\n"
                . "Confirmamos tu compra:\n"
                . "- Concepto: {$description}\n"
                . "- Monto: $" . number_format($amount, 2) . "\n"
                . "- Metodo de pago: {$metodo}\n"
                . "- Fecha: " . date('d/m/Y H:i') . "\n\n"
                . "Gracias por usar TuaniChat.";
            MailService::send($user['email'], $subject, $body, 'TuaniChat', false);
        } catch (\Throwable $e) {
            error_log("ReceiptModel emailReceipt ERROR: " . $e->getMessage());
        }
    }
}
