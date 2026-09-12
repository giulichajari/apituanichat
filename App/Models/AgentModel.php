<?php

namespace App\Models;

use App\Configs\Database;
use PDO;
use PDOException;
use Exception;

class AgentModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    private function conn(): PDO
    {
        $this->db = Database::getInstance()->getConnection();
        return $this->db;
    }

    public function create(int $userId, string $name, string $webhookUrl): int
    {
        try {
            $secret = bin2hex(random_bytes(32));
            // IMPORTANTE: capturar la conexion en una variable local UNA sola vez y
            // reusarla para el execute() y el lastInsertId(). Volver a llamar a conn()
            // (que hace un SELECT 1 de chequeo de salud) entre el INSERT y el
            // lastInsertId() lo resetea a 0 en este driver -- mismo patron ya usado
            // en ChatModel::sendMessage().
            $db = $this->conn();
            $stmt = $db->prepare("
                INSERT INTO agents (user_id, name, webhook_url, secret)
                VALUES (:user_id, :name, :webhook_url, :secret)
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':name' => $name,
                ':webhook_url' => $webhookUrl,
                ':secret' => $secret,
            ]);
            return (int)$db->lastInsertId();
        } catch (PDOException $e) {
            error_log("AgentModel::create: " . $e->getMessage());
            throw $e;
        }
    }

    public function getByUser(int $userId): array
    {
        try {
            $stmt = $this->conn()->prepare("
                SELECT id, name, webhook_url, created_at
                FROM agents WHERE user_id = ? ORDER BY created_at DESC
            ");
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("AgentModel::getByUser: " . $e->getMessage());
            return [];
        }
    }

    // Incluye el secret -- solo usar para chequeos internos (nunca exponer en getByUser)
    public function getById(int $agentId): ?array
    {
        try {
            $stmt = $this->conn()->prepare("SELECT * FROM agents WHERE id = ?");
            $stmt->execute([$agentId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Exception $e) {
            error_log("AgentModel::getById: " . $e->getMessage());
            return null;
        }
    }

    public function isOwnedBy(int $agentId, int $userId): bool
    {
        $agent = $this->getById($agentId);
        return $agent !== null && (int)$agent['user_id'] === $userId;
    }

    public function update(int $agentId, string $name, string $webhookUrl): bool
    {
        try {
            $stmt = $this->conn()->prepare("
                UPDATE agents SET name = ?, webhook_url = ? WHERE id = ?
            ");
            return $stmt->execute([$name, $webhookUrl, $agentId]);
        } catch (Exception $e) {
            error_log("AgentModel::update: " . $e->getMessage());
            return false;
        }
    }

    public function regenerateSecret(int $agentId): ?string
    {
        try {
            $secret = bin2hex(random_bytes(32));
            $stmt = $this->conn()->prepare("UPDATE agents SET secret = ? WHERE id = ?");
            $stmt->execute([$secret, $agentId]);
            return $secret;
        } catch (Exception $e) {
            error_log("AgentModel::regenerateSecret: " . $e->getMessage());
            return null;
        }
    }

    public function delete(int $agentId): bool
    {
        try {
            $stmt = $this->conn()->prepare("DELETE FROM agents WHERE id = ?");
            return $stmt->execute([$agentId]);
        } catch (Exception $e) {
            error_log("AgentModel::delete: " . $e->getMessage());
            return false;
        }
    }
}
