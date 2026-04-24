<?php

namespace App\Models;

use App\Configs\Database;
use PDO;
use PDOException;

class GroupsModel
{
    private PDO $db;
    private ?bool $hasGroupsDeletedAt = null;
    private ?bool $hasGroupsDeletedBy = null;
    private ?bool $hasGroupUsersDeletedAt = null;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name"
            );
            $stmt->execute([
                ':table_name' => $table,
                ':column_name' => $column
            ]);
            return ((int)$stmt->fetchColumn()) > 0;
        } catch (PDOException $e) {
            error_log("ColumnExists ERROR: " . $e->getMessage());
            return false;
        }
    }

    private function hasGroupsDeletedAt(): bool
    {
        if ($this->hasGroupsDeletedAt === null) {
            $this->hasGroupsDeletedAt = $this->columnExists('groups', 'deleted_at');
        }
        return $this->hasGroupsDeletedAt;
    }

    private function hasGroupsDeletedBy(): bool
    {
        if ($this->hasGroupsDeletedBy === null) {
            $this->hasGroupsDeletedBy = $this->columnExists('groups', 'deleted_by');
        }
        return $this->hasGroupsDeletedBy;
    }

    private function hasGroupUsersDeletedAt(): bool
    {
        if ($this->hasGroupUsersDeletedAt === null) {
            $this->hasGroupUsersDeletedAt = $this->columnExists('group_users', 'deleted_at');
        }
        return $this->hasGroupUsersDeletedAt;
    }

    // Crear un grupo
    public function createGroup(int $creatorId, string $name, array $members = []): int|false
    {
        try {
            $this->db->beginTransaction();

            // 1️⃣ Crear grupo
            $stmt = $this->db->prepare("INSERT INTO `groups` (name, created_by, created_at) VALUES (:name, :created_by, NOW())");
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
            $sql = "UPDATE `groups` SET name = :name WHERE id = :id";
            if ($this->hasGroupsDeletedAt()) {
                $sql .= " AND deleted_at IS NULL";
            }
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':name' => $name,
                ':id' => $idGroup
            ]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("UpdateGroup ERROR: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Borrado lógico: marca el grupo y desvincula miembros activos (group_users.deleted_at).
     * @return 'ok'|'not_found'|'already_deleted'|'forbidden'|'error'
     */
    public function softDeleteGroup(int $groupId, int $actorUserId): string
    {
        if (!$this->hasGroupsDeletedAt() || !$this->hasGroupUsersDeletedAt()) {
            error_log('[AUDIT] group_soft_delete blocked: missing soft-delete columns');
            return 'error';
        }

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("SELECT id, deleted_at, created_by FROM `groups` WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $groupId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $this->db->rollBack();
                return 'not_found';
            }
            if (!empty($row['deleted_at'])) {
                $this->db->rollBack();
                return 'already_deleted';
            }

            $isCreator = (int) $row['created_by'] === $actorUserId;
            $isAdmin = $this->isUserAdmin($groupId, $actorUserId);
            if (!$isCreator && !$isAdmin) {
                $this->db->rollBack();
                return 'forbidden';
            }

            if ($this->hasGroupsDeletedBy()) {
                $upd = $this->db->prepare(
                    "UPDATE `groups` SET deleted_at = NOW(), deleted_by = :uid WHERE id = :gid AND deleted_at IS NULL"
                );
                $upd->execute([':uid' => $actorUserId, ':gid' => $groupId]);
            } else {
                $upd = $this->db->prepare(
                    "UPDATE `groups` SET deleted_at = NOW() WHERE id = :gid AND deleted_at IS NULL"
                );
                $upd->execute([':gid' => $groupId]);
            }
            if ($upd->rowCount() === 0) {
                $this->db->rollBack();
                return 'already_deleted';
            }

            $mem = $this->db->prepare(
                "UPDATE `group_users` SET deleted_at = NOW() WHERE group_id = :gid AND deleted_at IS NULL"
            );
            $mem->execute([':gid' => $groupId]);

            $this->db->commit();
            error_log(sprintf('[AUDIT] group_soft_delete group_id=%d deleted_by_user_id=%d', $groupId, $actorUserId));
            return 'ok';
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("SoftDeleteGroup ERROR: " . $e->getMessage());
            return 'error';
        }
    }

    // Obtener un grupo por ID (solo activos)
    public function getGroupById(int $idGroup): array|false
    {
        try {
            $sql = "SELECT * FROM `groups` WHERE id = :id";
            if ($this->hasGroupsDeletedAt()) {
                $sql .= " AND deleted_at IS NULL";
            }
            $stmt = $this->db->prepare($sql);
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
            $groupsSoftFilter = $this->hasGroupsDeletedAt() ? "AND g.deleted_at IS NULL" : "";
            $groupUsersSoftFilter = $this->hasGroupUsersDeletedAt() ? "AND gu.deleted_at IS NULL" : "";

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
              {$groupUsersSoftFilter}
              {$groupsSoftFilter}
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
                $membersSoftFilter = $this->hasGroupUsersDeletedAt() ? "AND gu.deleted_at IS NULL" : "";
                $stmtMembers = $this->db->prepare("
                SELECT u.id, u.name, u.email, gu.is_admin
                FROM group_users gu
                INNER JOIN users u ON gu.user_id = u.id
                WHERE gu.group_id = :group_id {$membersSoftFilter}
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
        $groupsSoftJoin = $this->hasGroupsDeletedAt() ? "AND g.deleted_at IS NULL" : "";
        $groupUsersSoftFilter = $this->hasGroupUsersDeletedAt() ? "AND gu.deleted_at IS NULL" : "";
        $stmt = $this->db->prepare("
        SELECT gu.is_admin
        FROM group_users gu
        INNER JOIN `groups` g ON g.id = gu.group_id {$groupsSoftJoin}
        WHERE gu.group_id = ?
        AND gu.user_id = ?
        AND gu.is_admin = 1
        AND gu.left_at IS NULL
        {$groupUsersSoftFilter}
        LIMIT 1
    ");

        $stmt->execute([$groupId, $userId]);

        return (bool) $stmt->fetchColumn();
    }
    // Agregar usuario a un grupo
    public function addUserToGroup(int $groupId, int $userId, bool $isAdmin = false): bool
    {
        try {
            if ($this->hasGroupUsersDeletedAt()) {
                $stmt = $this->db->prepare("
                    INSERT INTO `group_users` (group_id, user_id, is_admin, joined_at) 
                    VALUES (:group_id, :user_id, :is_admin, NOW())
                    ON DUPLICATE KEY UPDATE deleted_at = NULL, is_admin = VALUES(is_admin)
                ");
            } else {
                $stmt = $this->db->prepare("
                    INSERT INTO `group_users` (group_id, user_id, is_admin, joined_at) 
                    VALUES (:group_id, :user_id, :is_admin, NOW())
                    ON DUPLICATE KEY UPDATE is_admin = VALUES(is_admin), joined_at = NOW()
                ");
            }
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
        $groupsSoftJoin = $this->hasGroupsDeletedAt() ? "AND g.deleted_at IS NULL" : "";
        $groupUsersSoftFilter = $this->hasGroupUsersDeletedAt() ? "AND gu.deleted_at IS NULL" : "";
        $stmt = $this->db->prepare("
        SELECT 1
        FROM group_users gu
        INNER JOIN `groups` g ON g.id = gu.group_id {$groupsSoftJoin}
        WHERE gu.group_id = ?
        AND gu.user_id = ?
        AND gu.left_at IS NULL
        {$groupUsersSoftFilter}
    ");

        $stmt->execute([$groupId, $userId]);

        return (bool) $stmt->fetch();
    }

    public function getGroupMessages($groupId, $userId)
    {
        $groupsSoftJoin = $this->hasGroupsDeletedAt() ? "AND g.deleted_at IS NULL" : "";
        $stmt = $this->db->prepare("
        SELECT 
            m.id,
            m.contenido AS message,
            m.enviado_en,
            u.name AS user_name,
            (m.user_id = ?) AS mine
        FROM mensajes_grupos m
        JOIN users u ON u.id = m.user_id
        INNER JOIN `groups` g ON g.id = m.group_id {$groupsSoftJoin}
        WHERE m.group_id = ?
        ORDER BY m.enviado_en ASC
    ");

        $stmt->execute([$userId, $groupId]);

        return $stmt->fetchAll();
    }

 public function createGroupMessage($groupId, $userId, $message, $tipo = 'texto')
{
    $stmt = $this->db->prepare("
        INSERT INTO mensajes_grupos
        (
            group_id,
            user_id,
            contenido,
            tipo,
            enviado_en,
            leido
        )
        VALUES (?, ?, ?, ?, NOW(), 0)
    ");

    return $stmt->execute([
        $groupId,
        $userId,
        $message,
        $tipo
    ]);
}
    // Quitar usuario de un grupo (marcar deleted_at)
    public function removeUserFromGroup(int $groupId, int $userId): bool
    {
        if (!$this->hasGroupUsersDeletedAt()) {
            return false;
        }

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
            $groupsSoftJoin = $this->hasGroupsDeletedAt() ? "AND g.deleted_at IS NULL" : "";
            $groupUsersSoftFilter = $this->hasGroupUsersDeletedAt() ? "AND gu.deleted_at IS NULL" : "";
            $stmt = $this->db->prepare("
                SELECT u.id, u.name, u.email, u.phone, gu.is_admin, gu.joined_at
                FROM `group_users` gu
                INNER JOIN `users` u ON gu.user_id = u.id
                INNER JOIN `groups` g ON g.id = gu.group_id {$groupsSoftJoin}
                WHERE gu.group_id = :group_id {$groupUsersSoftFilter}
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
