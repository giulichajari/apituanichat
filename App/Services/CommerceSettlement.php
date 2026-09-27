<?php
namespace App\Services;

use App\Configs\Database;
use App\Models\WalletModel;
use PDO;

/** Purchase debit -> held funds -> seller net + platform fee, or full payer refund. */
final class CommerceSettlement
{
    private PDO $db;
    public function __construct(?PDO $db = null) { $this->db = $db ?? Database::getInstance()->getConnection(); }

    public function hold(string $reference, int $transactionId, int $sellerId): void
    {
        if (!$this->db->inTransaction()) throw new \LogicException('La reserva debe pertenecer a la transacción de compra');
        if ($sellerId < 1 || strlen($reference) > 100) throw new \InvalidArgumentException('Proveedor inválido');
        $stmt = $this->db->prepare("SELECT * FROM wallet_transactions WHERE id = ? AND type = 'purchase' AND status = 'completed' FOR UPDATE");
        $stmt->execute([$transactionId]);
        $purchase = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$purchase) throw new \RuntimeException('No existe el débito que financia esta venta');
        $split = PlatformFees::split(UsdMoney::cents($purchase['amount']), PlatformFees::SELLER_BPS);
        $stmt = $this->db->prepare('INSERT INTO commerce_settlements (reference, purchase_transaction_id, payer_wallet_id, seller_user_id, gross_cents, fee_cents, net_cents) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$reference, $transactionId, $purchase['wallet_id'], $sellerId, $split['gross_cents'], $split['fee_cents'], $split['net_cents']]);
    }

    public function resolve(string $reference, string $action, int $actorId, string $reason): array
    {
        if (!in_array($action, ['release', 'refund'], true) || $actorId < 1 || trim($reason) === '' || strlen($reason) > 255) throw new \InvalidArgumentException('Indica una resolución y motivo válidos');
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM commerce_settlements WHERE reference = ? FOR UPDATE');
            $stmt->execute([$reference]);
            $sale = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sale) throw new \InvalidArgumentException('Venta no encontrada');
            $targetStatus = $action === 'release' ? 'released' : 'refunded';
            if ($sale['status'] === $targetStatus) { if ($ownsTransaction) $this->db->commit(); return ['status' => $targetStatus, 'replayed' => true]; }
            if ($sale['status'] !== 'held') throw new \DomainException('La venta ya tiene otra resolución; requiere conciliación');
            (new OrderLifecycle($this->db))->synchronize($sale, $action);
            if ($action === 'release') {
                $wallet = (new WalletModel($this->db))->getOrCreateWallet((int) $sale['seller_user_id']);
                $walletId = (int) $wallet['id'];
                $cents = (int) $sale['net_cents'];
            } else {
                $walletId = (int) $sale['payer_wallet_id'];
                $cents = (int) $sale['gross_cents'];
            }
            $stmt = $this->db->prepare('SELECT * FROM wallets WHERE id = ? FOR UPDATE');
            $stmt->execute([$walletId]);
            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$wallet) throw new \RuntimeException('Wallet no encontrada');
            $balance = UsdMoney::decimal(UsdMoney::cents($wallet['balance'], true) + $cents);
            $stmt = $this->db->prepare('UPDATE wallets SET balance = ? WHERE id = ?');
            $stmt->execute([$balance, $walletId]);
            $stmt = $this->db->prepare("INSERT INTO wallet_transactions (wallet_id, type, amount, balance_after, reference, status) VALUES (?, ?, ?, ?, ?, 'completed')");
            $stmt->execute([$walletId, $action === 'release' ? 'transfer_in' : 'refund', UsdMoney::decimal($cents), $balance, $reference]);
            $stmt = $this->db->prepare('UPDATE commerce_settlements SET status = ?, resolution_actor = ?, resolution_reason = ?, resolved_at = CURRENT_TIMESTAMP WHERE reference = ?');
            $stmt->execute([$targetStatus, $actorId, $reason, $reference]);
            if ($ownsTransaction) $this->db->commit();
            return ['status' => $targetStatus, 'replayed' => false];
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function pendingForSeller(int $userId): int
    {
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(net_cents), 0) FROM commerce_settlements WHERE seller_user_id = ? AND status = 'held'");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }
}
