<?php

namespace App\Models;

use App\Configs\Database;
use PDO;
use PDOException;

class ChatModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Crear un chat nuevo y asociar usuarios
     */
    public function createChat(array $userIds): int|false
    {
        try {
            $this->db->beginTransaction();

            $this->db->exec("INSERT INTO chats () VALUES ()");
            $chatId = (int)$this->db->lastInsertId();

            $stmt = $this->db->prepare("INSERT INTO chat_usuarios (chat_id, user_id) VALUES (:chat_id, :user_id)");
            foreach ($userIds as $uid) {
                $stmt->execute([
                    ':chat_id' => $chatId,
                    ':user_id' => $uid
                ]);
            }

            $this->db->commit();
            return $chatId;
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("Error creating chat: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtener todos los chats de un usuario
     */
    public function getChatsByUser(int $userId): array|false
    {
        try {
$stmt = $this->db->prepare("
    SELECT c.id AS chat_id,
           u.name AS name,
           c.created_at
    FROM chats c
    JOIN chat_usuarios cu ON cu.chat_id = c.id
    JOIN users u ON u.id = (
        SELECT user_id 
        FROM chat_usuarios 
        WHERE chat_id = c.id AND user_id != :user_id
        LIMIT 1
    )
    WHERE cu.user_id = :user_id;
");

$stmt->execute([':user_id' => $userId]);


            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting chats: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Enviar mensaje en un chat
     */
    public function sendMessage(int $chatId, int $userId, string $content, string $tipo = 'texto'): int|false
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO mensajes (chat_id, user_id, contenido, tipo)
                VALUES (:chat_id, :user_id, :contenido, :tipo)
            ");
            $stmt->execute([
                ':chat_id'   => $chatId,
                ':user_id'   => $userId,
                ':contenido' => $content,
                ':tipo'      => $tipo
            ]);
            return (int)$this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log("Error sending message: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtener mensajes de un chat
     */
    public function getMessages(int $chatId): array|false
    {
        try {
            $stmt = $this->db->prepare("
                SELECT m.id, m.chat_id, m.user_id, m.contenido, m.tipo, m.enviado_en, u.username
                FROM mensajes m
                INNER JOIN users u ON u.id = m.user_id
                WHERE m.chat_id = :chat_id
                ORDER BY m.enviado_en ASC
            ");
            $stmt->execute([':chat_id' => $chatId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting messages: " . $e->getMessage());
            return false;
        }
    }
}
