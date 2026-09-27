<?php
namespace App\Services;

use App\Configs\Database;
use PDO;

/** One transaction for the debit and the purchased order/entitlement. */
final class WalletCheckout
{
    private PDO $db;
    public function __construct(?PDO $db = null) { $this->db = $db ?? Database::getInstance()->getConnection(); }

    public function completed(int $userId, string $scope, string $key, array $intent): ?array
    {
        if ($userId < 1 || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $key)) throw new \InvalidArgumentException('Identificador de compra inválido');
        $stmt = $this->db->prepare('SELECT intent_hash, result_json FROM wallet_checkout_requests WHERE user_id = ? AND scope = ? AND request_key = ?');
        $stmt->execute([$userId, $scope, $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        if ($row['intent_hash'] !== hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR))) throw new \InvalidArgumentException('El identificador corresponde a otra compra');
        return $row['result_json'] === null ? null : json_decode($row['result_json'], true, 512, JSON_THROW_ON_ERROR) + ['replayed' => true];
    }

    public function run(int $userId, string $scope, string $key, array $intent, callable $purchase): array
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $key)) throw new \InvalidArgumentException('Falta el identificador de compra. Actualiza la aplicación.');
        $hash = hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR));
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('INSERT INTO wallet_checkout_requests (user_id, scope, request_key, intent_hash) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE request_key = request_key');
            $stmt->execute([$userId, $scope, $key, $hash]);
            $stmt = $this->db->prepare('SELECT * FROM wallet_checkout_requests WHERE user_id = ? AND scope = ? AND request_key = ? FOR UPDATE');
            $stmt->execute([$userId, $scope, $key]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($request['intent_hash'] !== $hash) throw new \InvalidArgumentException('El identificador de compra ya corresponde a otra operación.');
            if ($request['result_json'] !== null) {
                $result = json_decode($request['result_json'], true, 512, JSON_THROW_ON_ERROR);
                $this->db->commit();
                return $result + ['replayed' => true];
            }
            $result = $purchase();
            if (!is_array($result)) throw new \RuntimeException('Resultado de compra inválido');
            $stmt = $this->db->prepare('UPDATE wallet_checkout_requests SET result_json = ? WHERE user_id = ? AND scope = ? AND request_key = ?');
            $stmt->execute([json_encode($result, JSON_THROW_ON_ERROR), $userId, $scope, $key]);
            $this->db->commit();
            return $result + ['replayed' => false];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
