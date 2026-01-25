<?php

namespace App\Models;

use App\Configs\Database;
use PDO;
use PDOException;

class GroupsModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    // Crear un grupo
    public function createGroup(int $creatorId, string $name, array $members = []): int|false
    {
        try {
            $this->db->beginTransaction();

            // 1️⃣ Crear grupo
            $stmt = $this->db->prepare("INSERT INTO `groups`  (name, created_by, created_at) VALUES (:name, :created_by, NOW())");
            $stmt->execute([
                ':name' => $name,
                ':created_by' => $creatorId
            ]);
            $groupId = (int)$this->db->lastInsertId();

            // 2️⃣ Agregar creador como miembro/admin
            $stmtCreator = $this->db->prepare("INSERT INTO group_users (group_id, user_id, is_admin, joined_at) VALUES (:group_id, :user_id, 1, NOW())");
            $stmtCreator->execute([
                ':group_id' => $groupId,
                ':user_id' => $creatorId
            ]);

            // 3️⃣ Agregar otros miembros
            if (!empty($members)) {
                $stmtMember = $this->db->prepare("INSERT INTO group_users (group_id, user_id, is_admin, joined_at) VALUES (:group_id, :user_id, 0, NOW())");
                foreach ($members as $userId) {
                    // evitar duplicados, por si el creador está en la lista
                    if ($userId === $creatorId) continue;

                    $stmtMember->execute([
                        ':group_id' => $groupId,
                        ':user_id' => $userId
                    ]);
                }
            }

            $this->db->commit();
            return $groupId;
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("CreateGroup ERROR: " . $e->getMessage());
            return false;
        }
    }


    // Actualizar nombre del grupo
    public function updateGroup(int $idGroup, string $name): bool
    {
        try {
            $stmt = $this->db->prepare("UPDATE `groups` SET name = :name WHERE id = :id");
            $stmt->execute([
                ':name' => $name,
                ':id' => $idGroup
            ]);
            return true;
        } catch (PDOException $e) {
            error_log("UpdateGroup ERROR: " . $e->getMessage());
            return false;
        }
    }

    // Eliminar grupo
    public function deleteGroup(int $idGroup): bool
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM `groups` WHERE id = :id");
            return $stmt->execute([':id' => $idGroup]);
        } catch (PDOException $e) {
            error_log("DeleteGroup ERROR: " . $e->getMessage());
            return false;
        }
    }

    // Obtener un grupo por ID
    public function getGroupById(int $idGroup): array|false
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM `groups` WHERE id = :id");
            $stmt->execute([':id' => $idGroup]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("GetGroupById ERROR: " . $e->getMessage());
            return false;
        }
    }

    public function getGroupsByUser(int $userId, int $page = 1, int $perPage = 10): array|false
    {
        try {
            $offset = ($page - 1) * $perPage;

            $stmt = $this->db->prepare("
            SELECT 
                g.id, 
                g.name, 
                g.created_by, 
                g.created_at, 
                gu.is_admin
            FROM `group_users` gu
            INNER JOIN `groups` g ON gu.group_id = g.id
            WHERE gu.user_id = :user_id 
              AND gu.deleted_at IS NULL
            ORDER BY g.created_at DESC
            LIMIT :limit OFFSET :offset
        ");

            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Opcional: traer miembros de cada grupo
            foreach ($groups as &$group) {
                $stmtMembers = $this->db->prepare("
                SELECT u.id, u.name, u.email, gu.is_admin
                FROM group_users gu
                INNER JOIN users u ON gu.user_id = u.id
                WHERE gu.group_id = :group_id AND gu.deleted_at IS NULL
            ");
                $stmtMembers->execute([':group_id' => $group['id']]);
                $group['members'] = $stmtMembers->fetchAll(PDO::FETCH_ASSOC);
            }

            return $groups;
        } catch (PDOException $e) {
            error_log("GetGroupsByUser ERROR: " . $e->getMessage());
            return false;
        }
    }
public function addMultipleUsers($groupId, array $users)
{
    $stmt = $this->db->prepare("
        INSERT INTO group_users (group_id, user_id, joined_at, left_at, deleted_at)
        VALUES (?, ?, NOW(), NULL, NULL)
        ON DUPLICATE KEY UPDATE
            left_at = NULL,
            deleted_at = NULL,
            joined_at = NOW()
    ");

    foreach ($users as $userId) {
        $stmt->execute([$groupId, $userId]);
    }

    return true;
}
    public function isUserAdmin($groupId, $userId)
{
    $stmt = $this->db->prepare("
        SELECT is_admin
        FROM group_users
        WHERE group_id = ?
        AND user_id = ?
        AND is_admin = 1
        AND left_at IS NULL
        AND deleted_at IS NULL
        LIMIT 1
    ");

    $stmt->execute([$groupId, $userId]);

    return (bool) $stmt->fetchColumn();
}
    // Agregar usuario a un grupo
    public function addUserToGroup(int $groupId, int $userId, bool $isAdmin = false): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO `group_users` (group_id, user_id, is_admin, joined_at) 
                VALUES (:group_id, :user_id, :is_admin, NOW())
                ON DUPLICATE KEY UPDATE deleted_at = NULL, is_admin = VALUES(is_admin)
            ");
            return $stmt->execute([
                ':group_id' => $groupId,
                ':user_id' => $userId,
                ':is_admin' => $isAdmin ? 1 : 0
            ]);
        } catch (PDOException $e) {
            error_log("AddUserToGroup ERROR: " . $e->getMessage());
            return false;
        }
    }
    public function isUserInGroup($groupId, $userId)
    {
        $stmt = $this->db->prepare("
        SELECT 1 FROM group_users
        WHERE group_id = ?
        AND user_id = ?
        AND left_at IS NULL
    ");

        $stmt->execute([$groupId, $userId]);

        return (bool) $stmt->fetch();
    }

    public function getGroupMessages($groupId, $userId)
    {
        $stmt = $this->db->prepare("
        SELECT 
            m.id,
            m.contenido AS message,
            m.enviado_en,
            u.name AS user_name,
            (m.user_id = ?) AS mine
        FROM mensajes_grupos m
        JOIN users u ON u.id = m.user_id
        WHERE m.group_id = ?
        ORDER BY m.enviado_en ASC
    ");

        $stmt->execute([$userId, $groupId]);

        return $stmt->fetchAll();
    }

    public function createGroupMessage($groupId, $userId, $message)
    {
        $stmt = $this->db->prepare("
        INSERT INTO mensajes_grupos
        (group_id, user_id, contenido)
        VALUES (?, ?, ?)
    ");

        return $stmt->execute([$groupId, $userId, $message]);
    }
    // Quitar usuario de un grupo (marcar deleted_at)
    public function removeUserFromGroup(int $groupId, int $userId): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE `group_users` 
                SET deleted_at = NOW() 
                WHERE group_id = :group_id AND user_id = :user_id
            ");
            return $stmt->execute([
                ':group_id' => $groupId,
                ':user_id' => $userId
            ]);
        } catch (PDOException $e) {
            error_log("RemoveUserFromGroup ERROR: " . $e->getMessage());
            return false;
        }
    }

    // Listar usuarios de un grupo
    public function getUsersByGroup(int $groupId): array|false
    {
        try {
            $stmt = $this->db->prepare("
                SELECT u.id, u.name, u.email, u.phone, gu.is_admin, gu.joined_at
                FROM `group_users` gu
                INNER JOIN `users` u ON gu.user_id = u.id
                WHERE gu.group_id = :group_id AND gu.deleted_at IS NULL
                ORDER BY gu.joined_at ASC
            ");
            $stmt->execute([':group_id' => $groupId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("GetUsersByGroup ERROR: " . $e->getMessage());
            return false;
        }
    }
}
