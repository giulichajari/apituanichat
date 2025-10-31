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

    // En tu ChatModel.php
    public function addMessage($messageData)
    {
        try {
            $stmt = $this->db->prepare("
            INSERT INTO mensajes (chat_id, user_id, contenido, tipo, file_name, file_size, file_type, file_path, enviado_en) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

            $stmt->execute([
                $messageData['chat_id'],
                $messageData['user_id'],
                $messageData['contenido'],
                $messageData['tipo'],
                $messageData['file_name'] ?? null,
                $messageData['file_size'] ?? null,
                $messageData['file_type'] ?? null,
                $messageData['file_path'] ?? null
            ]);

            return $this->db->lastInsertId();
        } catch (\Exception $e) {
            error_log("Error al crear mensaje: " . $e->getMessage());
            return false;
        }
    }
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
    // En el método sendMessage del ChatModel
    public function sendMessage($chatId, $userId, $contenido, $tipo)
    {
        try {
            $stmt = $this->db->prepare("
            INSERT INTO mensajes (chat_id, user_id, contenido, tipo) 
            VALUES (:chat_id, :user_id, :contenido, :tipo)
        ");
            $stmt->execute([
                ':chat_id' => $chatId,
                ':user_id' => $userId,
                ':contenido' => $contenido,
                ':tipo' => $tipo
            ]);

            $messageId = $this->db->lastInsertId();

            // Actualizar last_message_at
            $this->db->prepare("
            UPDATE chats SET last_message_at = NOW()
            WHERE id = :chat_id
        ")->execute([':chat_id' => $chatId]);

            return $messageId;
        } catch (PDOException $e) {
            error_log("Error en sendMessage: " . $e->getMessage());
            throw $e;
        }
    }

    // 📄 Obtener mensajes - CORREGIDO
    public function getMessages($chatId, $userId = null)
    {
        $limit = (int)($_GET['limit'] ?? 50);
        $offset = (int)($_GET['offset'] ?? 0);

        $stmt = $this->db->prepare("
            SELECT m.*, 
                   u.name as user_name,
                   u.email as user_email,
                   CASE WHEN m.user_id = :user_id THEN 1 ELSE 0 END as is_own
            FROM mensajes m
            LEFT JOIN users u ON m.user_id = u.id
            WHERE m.chat_id = :chat_id
            ORDER BY m.id ASC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':chat_id', $chatId, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ✅ Marcar como leído - CORREGIDO
    public function markAsRead($chatId, $userId)
    {
        // Obtener último mensaje
        $stmt = $this->db->prepare("
            SELECT id FROM mensajes 
            WHERE chat_id = :chat_id 
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([':chat_id' => $chatId]);
        $lastId = $stmt->fetchColumn();

        $stmt = $this->db->prepare("
            UPDATE chat_usuarios 
            SET ultimo_no_leido = :ultimo, leido = 1
            WHERE chat_id = :chat_id AND user_id = :user_id
        ");
        $stmt->execute([
            ':ultimo' => $lastId ?? 0,
            ':chat_id' => $chatId,
            ':user_id' => $userId
        ]);

        return true;
    }

    // 📬 Listar chats del usuario
    public function getChatsByUser($userId)
    {
        $stmt = $this->db->prepare("
            SELECT c.*, 
                   (SELECT contenido FROM mensajes m WHERE m.chat_id = c.id ORDER BY m.id DESC LIMIT 1) as ultimo_mensaje,
                   (SELECT enviado_en FROM mensajes m WHERE m.chat_id = c.id ORDER BY m.id DESC LIMIT 1) as ultimo_mensaje_fecha
            FROM chats c
            JOIN chat_usuarios cu ON cu.chat_id = c.id
            WHERE cu.user_id = :user_id
            ORDER BY c.last_message_at DESC
        ");
        $stmt->execute([':user_id' => $userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
