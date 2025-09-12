<?php

namespace App\Models;

use App\Configs\Database;
use PDO;
use PDOException;

class SignalModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Guardar señal (offer, answer o candidate)
     */
    public function addSignal(string $sessionId, string $type, array|string $payload): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO webrtc_signaling (session_id, type, payload)
                VALUES (:session_id, :type, :payload)
            ");
            return $stmt->execute([
                ':session_id' => $sessionId,
                ':type' => $type,
                ':payload' => is_array($payload) ? json_encode($payload) : $payload
            ]);
        } catch (PDOException $e) {
            error_log("Error adding signal: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtener la última señal por tipo (offer o answer)
     */
    public function getSignal(string $sessionId, string $type): array|false
    {
        try {
            $stmt = $this->db->prepare("
                SELECT payload
                FROM webrtc_signaling
                WHERE session_id = :session_id AND type = :type
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute([
                ':session_id' => $sessionId,
                ':type' => $type
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? json_decode($row['payload'], true) : [];
        } catch (PDOException $e) {
            error_log("Error getting signal: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtener todos los candidates de una sesión
     */
    public function getCandidates(string $sessionId): array|false
    {
        try {
            $stmt = $this->db->prepare("
                SELECT payload
                FROM webrtc_signaling
                WHERE session_id = :session_id AND type = 'candidate'
                ORDER BY id ASC
            ");
            $stmt->execute([':session_id' => $sessionId]);
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

            return array_map(fn($c) => json_decode($c, true), $rows);
        } catch (PDOException $e) {
            error_log("Error getting candidates: " . $e->getMessage());
            return false;
        }
    }
}
