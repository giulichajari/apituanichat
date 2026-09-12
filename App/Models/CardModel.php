<?php
namespace App\Models;
use App\Configs\Database;
use PDO;

class CardModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function getSquareCustomerId(int $userId): ?string
    {
        $stmt = $this->db->prepare("SELECT square_customer_id FROM square_customers WHERE user_id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['square_customer_id'] ?? null;
    }

    public function saveSquareCustomerId(int $userId, string $squareCustomerId): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO square_customers (user_id, square_customer_id)
            VALUES (:user_id, :square_customer_id)
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':square_customer_id' => $squareCustomerId,
        ]);
    }

    public function saveCard(array $data): ?int
    {
        $stmt = $this->db->prepare("
            INSERT INTO saved_cards (user_id, square_card_id, card_brand, last_4, exp_month, exp_year, is_default)
            VALUES (:user_id, :square_card_id, :card_brand, :last_4, :exp_month, :exp_year, :is_default)
        ");
        $ok = $stmt->execute([
            ':user_id' => $data['user_id'],
            ':square_card_id' => $data['square_card_id'],
            ':card_brand' => $data['card_brand'] ?? null,
            ':last_4' => $data['last_4'] ?? null,
            ':exp_month' => $data['exp_month'] ?? null,
            ':exp_year' => $data['exp_year'] ?? null,
            ':is_default' => $data['is_default'] ?? 0,
        ]);
        return $ok ? (int) $this->db->lastInsertId() : null;
    }

    public function getCardsByUser(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, square_card_id, card_brand, last_4, exp_month, exp_year, is_default
            FROM saved_cards
            WHERE user_id = ? AND status = 'active'
            ORDER BY is_default DESC, created_at DESC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCardById(int $cardId, int $userId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM saved_cards WHERE id = ? AND user_id = ? AND status = 'active'");
        $stmt->execute([$cardId, $userId]);
        $card = $stmt->fetch(PDO::FETCH_ASSOC);
        return $card ?: null;
    }

    public function disableCard(int $cardId, int $userId): bool
    {
        $stmt = $this->db->prepare("UPDATE saved_cards SET status = 'disabled' WHERE id = ? AND user_id = ?");
        return $stmt->execute([$cardId, $userId]);
    }

    public function setDefault(int $cardId, int $userId): bool
    {
        $stmt = $this->db->prepare("UPDATE saved_cards SET is_default = 0 WHERE user_id = ?");
        $stmt->execute([$userId]);
        $stmt = $this->db->prepare("UPDATE saved_cards SET is_default = 1 WHERE id = ? AND user_id = ?");
        return $stmt->execute([$cardId, $userId]);
    }
}
