<?php
namespace App\Models;
use App\Configs\Database;
use PDO;
use Exception;

class WalletModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    private function generateAccountNumber(): string
    {
        do {
            $number = (string) random_int(1000000000, 9999999999);
            $stmt = $this->db->prepare("SELECT id FROM wallets WHERE account_number = ?");
            $stmt->execute([$number]);
            $exists = $stmt->fetch(PDO::FETCH_ASSOC);
        } while ($exists);
        return $number;
    }

    public function getOrCreateWallet(int $userId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM wallets WHERE user_id = ?");
        $stmt->execute([$userId]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($wallet) {
            return $wallet;
        }

        $accountNumber = $this->generateAccountNumber();
        $stmt = $this->db->prepare("
            INSERT INTO wallets (user_id, account_number, balance)
            VALUES (:user_id, :account_number, 0.00)
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':account_number' => $accountNumber
        ]);

        $stmt = $this->db->prepare("SELECT * FROM wallets WHERE user_id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getByAccountNumber(string $accountNumber): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM wallets WHERE account_number = ?");
        $stmt->execute([$accountNumber]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
        return $wallet ?: null;
    }

    public function createRecharge(array $data): ?int
    {
        $stmt = $this->db->prepare("
            INSERT INTO wallet_recharges (user_id, amount, fee_amount, net_amount, currency, payment_link_id, payment_link_url, idempotency_key, status)
            VALUES (:user_id, :amount, :fee_amount, :net_amount, :currency, :payment_link_id, :payment_link_url, :idempotency_key, 'pending')
        ");
        $ok = $stmt->execute([
            ':user_id' => $data['user_id'],
            ':amount' => $data['amount'],
            ':fee_amount' => $data['fee_amount'],
            ':net_amount' => $data['net_amount'],
            ':currency' => $data['currency'] ?? 'USD',
            ':payment_link_id' => $data['payment_link_id'] ?? null,
            ':payment_link_url' => $data['payment_link_url'] ?? null,
            ':idempotency_key' => $data['idempotency_key'] ?? null,
        ]);
        return $ok ? (int) $this->db->lastInsertId() : null;
    }

    public function getRechargeByPaymentLinkId(string $paymentLinkId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM wallet_recharges WHERE payment_link_id = ?");
        $stmt->execute([$paymentLinkId]);
        $recharge = $stmt->fetch(PDO::FETCH_ASSOC);
        return $recharge ?: null;
    }

    // Acredita el saldo y marca la recarga como completada. Idempotente: si ya estaba completada, no vuelve a acreditar.
    public function markRechargeCompleted(int $rechargeId): bool
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("SELECT * FROM wallet_recharges WHERE id = ? FOR UPDATE");
            $stmt->execute([$rechargeId]);
            $recharge = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$recharge || $recharge['status'] === 'completed') {
                $this->db->commit();
                return false;
            }

            $wallet = $this->getOrCreateWallet((int) $recharge['user_id']);

            $stmt = $this->db->prepare("SELECT * FROM wallets WHERE id = ? FOR UPDATE");
            $stmt->execute([$wallet['id']]);
            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

            $newBalance = round((float) $wallet['balance'] + (float) $recharge['net_amount'], 2);

            $stmt = $this->db->prepare("UPDATE wallets SET balance = ? WHERE id = ?");
            $stmt->execute([$newBalance, $wallet['id']]);

            $stmt = $this->db->prepare("
                INSERT INTO wallet_transactions (wallet_id, type, amount, balance_after, reference, status)
                VALUES (?, 'recharge', ?, ?, ?, 'completed')
            ");
            $stmt->execute([$wallet['id'], $recharge['net_amount'], $newBalance, 'recharge_' . $recharge['id']]);

            $stmt = $this->db->prepare("UPDATE wallet_recharges SET status = 'completed' WHERE id = ?");
            $stmt->execute([$rechargeId]);

            $this->db->commit();
            $this->checkAndFlagSuspicious($wallet['id'], (float) $recharge['net_amount'], null);
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log('WalletModel::markRechargeCompleted error: ' . $e->getMessage());
            return false;
        }
    }

    // Transferencia atómica entre dos wallets, con bloqueo de filas para evitar condiciones de carrera.
    public function transfer(int $fromUserId, string $toAccountNumber, float $amount): array
    {
        if ($amount <= 0) {
            return ['success' => false, 'message' => 'Monto inválido'];
        }

        $this->db->beginTransaction();
        try {
            $fromWallet = $this->getOrCreateWallet($fromUserId);

            $stmt = $this->db->prepare("SELECT * FROM wallets WHERE id = ? FOR UPDATE");
            $stmt->execute([$fromWallet['id']]);
            $fromWallet = $stmt->fetch(PDO::FETCH_ASSOC);

            if (($fromWallet['status'] ?? 'active') === 'frozen') {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Tu wallet está congelada. Contacta a soporte.'];
            }

            $stmt = $this->db->prepare("SELECT * FROM wallets WHERE account_number = ? FOR UPDATE");
            $stmt->execute([$toAccountNumber]);
            $toWallet = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$toWallet) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Número de cuenta no encontrado'];
            }

            if (($toWallet['status'] ?? 'active') === 'frozen') {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'La wallet destino está congelada'];
            }

            if ((int) $toWallet['user_id'] === $fromUserId) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'No puedes transferirte a ti mismo'];
            }

            if ((float) $fromWallet['balance'] < $amount) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Saldo insuficiente'];
            }

            $newFromBalance = round((float) $fromWallet['balance'] - $amount, 2);
            $newToBalance = round((float) $toWallet['balance'] + $amount, 2);

            $stmt = $this->db->prepare("UPDATE wallets SET balance = ? WHERE id = ?");
            $stmt->execute([$newFromBalance, $fromWallet['id']]);
            $stmt->execute([$newToBalance, $toWallet['id']]);

            $stmt = $this->db->prepare("
                INSERT INTO wallet_transactions (wallet_id, type, amount, balance_after, related_user_id, status)
                VALUES (?, 'transfer_out', ?, ?, ?, 'completed')
            ");
            $stmt->execute([$fromWallet['id'], $amount, $newFromBalance, $toWallet['user_id']]);

            $stmt = $this->db->prepare("
                INSERT INTO wallet_transactions (wallet_id, type, amount, balance_after, related_user_id, status)
                VALUES (?, 'transfer_in', ?, ?, ?, 'completed')
            ");
            $stmt->execute([$toWallet['id'], $amount, $newToBalance, $fromWallet['user_id']]);

            $this->db->commit();
            $this->checkAndFlagSuspicious($fromWallet['id'], $amount, null);
            return ['success' => true, 'new_balance' => $newFromBalance];
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log('WalletModel::transfer error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error interno al transferir'];
        }
    }

    // Débito para compras (Shop/Eats/Ride) sin pasar por Square.
    public function debitForPurchase(int $userId, float $amount, string $reference): array
    {
        if ($amount <= 0) {
            return ['success' => false, 'message' => 'Monto inválido'];
        }

        $this->db->beginTransaction();
        try {
            $wallet = $this->getOrCreateWallet($userId);

            $stmt = $this->db->prepare("SELECT * FROM wallets WHERE id = ? FOR UPDATE");
            $stmt->execute([$wallet['id']]);
            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

            if (($wallet['status'] ?? 'active') === 'frozen') {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Tu wallet está congelada. Contacta a soporte.'];
            }

            $chargeWallet = $wallet;
            $viaFamily = false;

            if ((float) $wallet['balance'] < $amount) {
                $familyModel = new \App\Models\FamilyModel();
                $parentId = $familyModel->getWalletSharePayer($userId);

                if (!$parentId) {
                    $this->db->rollBack();
                    return ['success' => false, 'message' => 'Saldo insuficiente'];
                }

                $parentWallet = $this->getOrCreateWallet($parentId);
                $stmt2 = $this->db->prepare("SELECT * FROM wallets WHERE id = ? FOR UPDATE");
                $stmt2->execute([$parentWallet['id']]);
                $parentWallet = $stmt2->fetch(PDO::FETCH_ASSOC);

                if (($parentWallet['status'] ?? 'active') === 'frozen') {
                    $this->db->rollBack();
                    return ['success' => false, 'message' => 'Saldo insuficiente y la wallet familiar esta congelada'];
                }
                if ((float) $parentWallet['balance'] < $amount) {
                    $this->db->rollBack();
                    return ['success' => false, 'message' => 'Saldo insuficiente (tampoco alcanza en la wallet familiar)'];
                }

                $chargeWallet = $parentWallet;
                $viaFamily = true;
            }

            $newBalance = round((float) $chargeWallet['balance'] - $amount, 2);

            $stmt = $this->db->prepare("UPDATE wallets SET balance = ? WHERE id = ?");
            $stmt->execute([$newBalance, $chargeWallet['id']]);

            $finalReference = $viaFamily ? ($reference . ' (compartido - usuario ' . $userId . ')') : $reference;

            $stmt = $this->db->prepare("
                INSERT INTO wallet_transactions (wallet_id, type, amount, balance_after, reference, status)
                VALUES (?, 'purchase', ?, ?, ?, 'completed')
            ");
            $stmt->execute([$chargeWallet['id'], $amount, $newBalance, $finalReference]);

            $this->db->commit();
            return ['success' => true, 'new_balance' => $newBalance, 'via_family' => $viaFamily];
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log('WalletModel::debitForPurchase error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error interno al debitar'];
        }
    }

    // Crédito directo (cobro instantáneo con tarjeta guardada, sin pasar por webhook).
    public function creditRechargeDirect(int $userId, float $amount, float $feeAmount, float $netAmount, string $currency, string $squarePaymentId): array
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                INSERT INTO wallet_recharges (user_id, amount, fee_amount, net_amount, currency, payment_link_id, idempotency_key, status)
                VALUES (:user_id, :amount, :fee_amount, :net_amount, :currency, NULL, :square_payment_id, 'completed')
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':amount' => $amount,
                ':fee_amount' => $feeAmount,
                ':net_amount' => $netAmount,
                ':currency' => $currency,
                ':square_payment_id' => $squarePaymentId,
            ]);
            $rechargeId = (int) $this->db->lastInsertId();

            $wallet = $this->getOrCreateWallet($userId);
            $stmt = $this->db->prepare("SELECT * FROM wallets WHERE id = ? FOR UPDATE");
            $stmt->execute([$wallet['id']]);
            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

            $newBalance = round((float) $wallet['balance'] + $netAmount, 2);

            $stmt = $this->db->prepare("UPDATE wallets SET balance = ? WHERE id = ?");
            $stmt->execute([$newBalance, $wallet['id']]);

            $stmt = $this->db->prepare("
                INSERT INTO wallet_transactions (wallet_id, type, amount, balance_after, reference, status)
                VALUES (?, 'recharge', ?, ?, ?, 'completed')
            ");
            $stmt->execute([$wallet['id'], $netAmount, $newBalance, 'recharge_' . $rechargeId]);

            $this->db->commit();
            $this->checkAndFlagSuspicious($wallet['id'], $netAmount, null);
            return ['success' => true, 'new_balance' => $newBalance];
        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log('WalletModel::creditRechargeDirect error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error interno al acreditar'];
        }
    }

    public function getTransactions(int $userId, int $limit = 50): array
    {
        $wallet = $this->getOrCreateWallet($userId);
        $stmt = $this->db->prepare("
            SELECT * FROM wallet_transactions
            WHERE wallet_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $wallet['id'], PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ---------- Panel de administración ----------

    public function getAllWalletsWithUser(int $limit = 50, int $offset = 0, string $search = ''): array
    {
        $sql = "
            SELECT w.id, w.user_id, w.account_number, w.balance, w.status, w.lock_reason, u.name, u.email
            FROM wallets w
            LEFT JOIN users u ON u.id = w.user_id
        ";
        $params = [];
        if ($search !== '') {
            $sql .= " WHERE u.name LIKE :search OR u.email LIKE :search OR w.account_number LIKE :search ";
            $params[':search'] = "%$search%";
        }
        $sql .= " ORDER BY w.balance DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSystemOverview(): array
    {
        $stmt = $this->db->query("SELECT COUNT(*) as total_wallets, COALESCE(SUM(balance),0) as total_balance FROM wallets");
        $wallets = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $this->db->query("SELECT COALESCE(SUM(net_amount),0) as total FROM wallet_recharges WHERE status = 'completed'");
        $recharges = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $this->db->query("SELECT COALESCE(SUM(amount),0) as total, COUNT(*) as count FROM wallet_transactions WHERE type = 'transfer_out'");
        $transfers = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $this->db->query("SELECT COUNT(*) as total FROM wallet_alerts WHERE resolved = 0");
        $alerts = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'total_wallets' => (int) $wallets['total_wallets'],
            'total_balance' => (float) $wallets['total_balance'],
            'total_recharged' => (float) $recharges['total'],
            'total_transferred' => (float) $transfers['total'],
            'transfer_count' => (int) $transfers['count'],
            'open_alerts' => (int) $alerts['total'],
        ];
    }

    public function getAllTransactions(int $limit = 100, int $offset = 0, ?string $type = null): array
    {
        $sql = "
            SELECT t.*, w.user_id, w.account_number
            FROM wallet_transactions t
            JOIN wallets w ON w.id = t.wallet_id
        ";
        $params = [];
        if ($type) {
            $sql .= " WHERE t.type = :type ";
            $params[':type'] = $type;
        }
        $sql .= " ORDER BY t.created_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function setWalletStatus(int $walletUserId, string $status): bool
    {
        if ($status === 'active') {
            $stmt = $this->db->prepare("UPDATE wallets SET status = 'active', pin_attempts = 0, lock_reason = NULL WHERE user_id = ?");
            return $stmt->execute([$walletUserId]);
        }
        $stmt = $this->db->prepare("UPDATE wallets SET status = 'frozen', lock_reason = 'admin' WHERE user_id = ?");
        return $stmt->execute([$walletUserId]);
    }

    public function isWalletFrozen(int $userId): bool
    {
        $stmt = $this->db->prepare("SELECT status FROM wallets WHERE user_id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row && $row['status'] === 'frozen';
    }

    // Ajuste manual de saldo por un admin (positivo suma, negativo resta). Queda registrado en el libro contable.
    public function adjustBalance(int $userId, float $amount, string $reason): array
    {
        $this->db->beginTransaction();
        try {
            $wallet = $this->getOrCreateWallet($userId);
            $stmt = $this->db->prepare("SELECT * FROM wallets WHERE id = ? FOR UPDATE");
            $stmt->execute([$wallet['id']]);
            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

            $newBalance = round((float) $wallet['balance'] + $amount, 2);
            if ($newBalance < 0) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'El ajuste dejaría el saldo en negativo'];
            }

            $stmt = $this->db->prepare("UPDATE wallets SET balance = ? WHERE id = ?");
            $stmt->execute([$newBalance, $wallet['id']]);

            $stmt = $this->db->prepare("
                INSERT INTO wallet_transactions (wallet_id, type, amount, balance_after, reference, status)
                VALUES (?, 'adjustment', ?, ?, ?, 'completed')
            ");
            $stmt->execute([$wallet['id'], abs($amount), $newBalance, 'admin_adjustment: ' . $reason]);

            $this->db->commit();
            return ['success' => true, 'new_balance' => $newBalance];
        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log('WalletModel::adjustBalance error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error interno al ajustar saldo'];
        }
    }

    // ---------- Alertas de movimientos sospechosos ----------

    public function checkAndFlagSuspicious(int $walletId, float $amount, ?int $transactionId, float $highAmountThreshold = 500.00, int $freqCount = 5, int $freqMinutes = 10): void
    {
        try {
            if ($amount >= $highAmountThreshold) {
                $this->createAlert($walletId, 'high_amount', $transactionId, "Monto de $" . number_format($amount, 2) . " supera el umbral de $" . number_format($highAmountThreshold, 2));
            }

            $stmt = $this->db->prepare("
                SELECT COUNT(*) as cnt FROM wallet_transactions
                WHERE wallet_id = ? AND type IN ('transfer_out','recharge')
                AND created_at >= (NOW() - INTERVAL ? MINUTE)
            ");
            $stmt->execute([$walletId, $freqMinutes]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ((int) $row['cnt'] >= $freqCount) {
                $this->createAlert($walletId, 'high_frequency', $transactionId, (int) $row['cnt'] . " movimientos en los últimos $freqMinutes minutos");
            }
        } catch (\Exception $e) {
            error_log('WalletModel::checkAndFlagSuspicious error: ' . $e->getMessage());
        }
    }

    private function createAlert(int $walletId, string $type, ?int $transactionId, string $details): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO wallet_alerts (wallet_id, type, transaction_id, details)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$walletId, $type, $transactionId, $details]);
    }

    public function getAlerts(bool $onlyUnresolved = true): array
    {
        $sql = "
            SELECT a.*, w.user_id, w.account_number, u.name, u.email
            FROM wallet_alerts a
            JOIN wallets w ON w.id = a.wallet_id
            LEFT JOIN users u ON u.id = w.user_id
        ";
        if ($onlyUnresolved) {
            $sql .= " WHERE a.resolved = 0 ";
        }
        $sql .= " ORDER BY a.created_at DESC";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function resolveAlert(int $alertId): bool
    {
        $stmt = $this->db->prepare("UPDATE wallet_alerts SET resolved = 1 WHERE id = ?");
        return $stmt->execute([$alertId]);
    }


    // ---------- PIN de seguridad ----------

    public function setPin(int $userId, string $pin): bool
    {
        $wallet = $this->getOrCreateWallet($userId);
        $hash = password_hash($pin, PASSWORD_DEFAULT);
        $stmt = $this->db->prepare("UPDATE wallets SET pin_hash = ?, pin_enabled = 1, pin_attempts = 0 WHERE id = ?");
        return $stmt->execute([$hash, $wallet['id']]);
    }

    public function disablePin(int $userId): bool
    {
        $wallet = $this->getOrCreateWallet($userId);
        $stmt = $this->db->prepare("UPDATE wallets SET pin_hash = NULL, pin_enabled = 0, pin_attempts = 0 WHERE id = ?");
        return $stmt->execute([$wallet['id']]);
    }

    public function isPinEnabled(int $userId): bool
    {
        $wallet = $this->getOrCreateWallet($userId);
        return (bool) $wallet['pin_enabled'];
    }

    // Devuelve ['ok' => true] si el PIN es correcto.
    // Si es incorrecto, suma un intento y bloquea la wallet al segundo error consecutivo.
    public function verifyPin(int $userId, string $pin): array
    {
        $wallet = $this->getOrCreateWallet($userId);

        if (($wallet['status'] ?? 'active') === 'frozen') {
            return ['ok' => false, 'locked' => true, 'message' => 'Tu wallet está bloqueada. Contacta a soporte.'];
        }

        if (!$wallet['pin_enabled'] || !$wallet['pin_hash']) {
            return ['ok' => false, 'message' => 'No tienes un PIN configurado'];
        }

        if (password_verify($pin, $wallet['pin_hash'])) {
            $stmt = $this->db->prepare("UPDATE wallets SET pin_attempts = 0 WHERE id = ?");
            $stmt->execute([$wallet['id']]);
            return ['ok' => true];
        }

        $newAttempts = (int) $wallet['pin_attempts'] + 1;

        if ($newAttempts >= 2) {
            $stmt = $this->db->prepare("UPDATE wallets SET pin_attempts = ?, status = 'frozen', lock_reason = 'pin_lockout' WHERE id = ?");
            $stmt->execute([$newAttempts, $wallet['id']]);
            return ['ok' => false, 'locked' => true, 'message' => 'PIN incorrecto 2 veces. Tu wallet fue bloqueada por seguridad. Contacta a soporte.'];
        }

        $stmt = $this->db->prepare("UPDATE wallets SET pin_attempts = ? WHERE id = ?");
        $stmt->execute([$newAttempts, $wallet['id']]);
        return ['ok' => false, 'message' => 'PIN incorrecto. Te queda 1 intento antes de que se bloquee tu wallet.'];
    }

    public function getUserEmail(int $userId): ?string
    {
        $stmt = $this->db->prepare("SELECT email FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['email'] ?? null;
    }

}
