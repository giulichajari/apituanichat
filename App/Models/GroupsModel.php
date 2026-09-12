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

    // Genera un PIN unico de 4 digitos para grupos privados
    private function generateUniqueJoinPin(): string
    {
        do {
            $pin = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            $stmt = $this->db->prepare("SELECT id FROM `groups` WHERE join_pin = :pin LIMIT 1");
            $stmt->execute([':pin' => $pin]);
            $exists = $stmt->fetch();
        } while ($exists);
        return $pin;
    }

    // Crear un grupo
    public function createGroup(int $creatorId, string $name, array $members = [], bool $isPublic = true, ?string $joinPin = null): int|false
    {
        try {
            $this->db->beginTransaction();

            if (!$isPublic && !$joinPin) {
                $joinPin = $this->generateUniqueJoinPin();
            }
            if ($isPublic) {
                $joinPin = null;
            }

            $stmt = $this->db->prepare("INSERT INTO `groups` (name, created_by, is_public, join_pin, created_at) VALUES (:name, :created_by, :is_public, :join_pin, NOW())");
            $stmt->execute([
                ':name' => $name,
                ':created_by' => $creatorId,
                ':is_public' => $isPublic ? 1 : 0,
                ':join_pin' => $joinPin
            ]);
            $groupId = (int)$this->db->lastInsertId();

            $stmtCreator = $this->db->prepare("INSERT INTO group_users (group_id, user_id, is_admin, joined_at) VALUES (:group_id, :user_id, 1, NOW())");
            $stmtCreator->execute([
                ':group_id' => $groupId,
                ':user_id' => $creatorId
            ]);

            if (!empty($members)) {
                $stmtMember = $this->db->prepare("INSERT INTO group_users (group_id, user_id, is_admin, joined_at) VALUES (:group_id, :user_id, 0, NOW())");
                foreach ($members as $userId) {
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

    // Puede el usuario mandar mensajes/archivos en este grupo?
    // Si solo_admins_chatean esta apagado, cualquier miembro puede.
    // Si esta prendido, solo el creador o un admin del grupo.
    public function canUserSendGroupMessage(int $groupId, int $userId): bool
    {
        $group = $this->getGroupById($groupId);
        if (!$group) {
            return false;
        }
        if ((int) ($group['solo_admins_chatean'] ?? 0) !== 1) {
            return true;
        }
        if ((int) $group['created_by'] === $userId) {
            return true;
        }
        return (bool) $this->isUserAdmin($groupId, $userId);
    }

    public function setSoloAdminsChatean(int $groupId, bool $value): bool
    {
        try {
            $stmt = $this->db->prepare("UPDATE `groups` SET solo_admins_chatean = :val WHERE id = :id");
            $stmt->execute([
                ':val' => $value ? 1 : 0,
                ':id' => $groupId
            ]);
            return true;
        } catch (PDOException $e) {
            error_log("SetSoloAdminsChatean ERROR: " . $e->getMessage());
            return false;
        }
    }

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

    // Grupos que el usuario TODAVIA NO integra (para descubrir y unirse)
    public function discoverGroups(int $userId, int $page = 1, int $perPage = 10, ?string $search = null): array|false
    {
        try {
            $offset = ($page - 1) * $perPage;
            $groupsSoftFilter = $this->hasGroupsDeletedAt() ? "AND g.deleted_at IS NULL" : "";
            $searchFilter = $search ? "AND g.name LIKE :search" : "";

            $stmt = $this->db->prepare("
            SELECT
                g.id,
                g.name,
                g.created_by,
                g.created_at,
                g.is_public
            FROM `groups` g
            WHERE g.id NOT IN (
                SELECT gu.group_id FROM `group_users` gu WHERE gu.user_id = :user_id
            )
              {$groupsSoftFilter}
              {$searchFilter}
            ORDER BY g.created_at DESC
            LIMIT :limit OFFSET :offset
        ");

            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            if ($search) {
                $stmt->bindValue(':search', '%' . $search . '%', PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("DiscoverGroups ERROR: " . $e->getMessage());
            return false;
        }
    }

    public function getGroupsByUser(int $userId, int $page = 1, int $perPage = 10, ?string $search = null): array|false
    {
        try {
            $offset = ($page - 1) * $perPage;
            $groupsSoftFilter = $this->hasGroupsDeletedAt() ? "AND g.deleted_at IS NULL" : "";
            $groupUsersSoftFilter = $this->hasGroupUsersDeletedAt() ? "AND gu.deleted_at IS NULL" : "";
            $searchFilter = $search ? "AND g.name LIKE :search" : "";

            $stmt = $this->db->prepare("
            SELECT 
                g.id, 
                g.name, 
                g.created_by, 
                g.created_at, 
                g.is_public,
                g.solo_admins_chatean,
                g.join_pin,
                gu.is_admin
            FROM `group_users` gu
            INNER JOIN `groups` g ON gu.group_id = g.id
            WHERE gu.user_id = :user_id 
              {$groupUsersSoftFilter}
              {$groupsSoftFilter}
              {$searchFilter}
            ORDER BY g.created_at DESC
            LIMIT :limit OFFSET :offset
        ");

            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            if ($search) {
                $stmt->bindValue(':search', '%' . $search . '%', PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

    // Mensajes del grupo, con soporte de archivos/mensajes pagados (visibilidad + precio)
    public function getGroupMessages($groupId, $userId)
    {
        $groupsSoftJoin = $this->hasGroupsDeletedAt() ? "AND g.deleted_at IS NULL" : "";
        $stmt = $this->db->prepare("
        SELECT 
            m.id,
            m.user_id,
            m.contenido AS message,
            m.tipo,
            m.file_url,
            m.file_name,
            m.file_size,
            m.mime_type,
            m.latitude,
            m.longitude,
            m.visibilidad,
            m.precio,
            m.enviado_en,
            u.name AS user_name,
            (m.user_id = ?) AS mine,
            (
                m.visibilidad = 'publico'
                OR m.user_id = ?
                OR EXISTS (
                    SELECT 1 FROM mensajes_pagos mp
                    WHERE mp.mensaje_id = m.id
                      AND mp.usuario_id = ?
                      AND mp.estado = 'completado'
                )
            ) AS desbloqueado
        FROM mensajes_grupos m
        JOIN users u ON u.id = m.user_id
        INNER JOIN `groups` g ON g.id = m.group_id {$groupsSoftJoin}
        WHERE m.group_id = ?
        ORDER BY m.enviado_en ASC
    ");

        $stmt->execute([$userId, $userId, $userId, $groupId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['desbloqueado'] = (bool) $row['desbloqueado'];
            if (!$row['desbloqueado']) {
                $row['file_url'] = null;
            }
        }
        unset($row);

        return $rows;
    }

    // Trae un mensaje de grupo puntual para validar visibilidad/precio antes de generar el link de pago
    public function getMessageForPayment(int $mensajeId, int $groupId): array|false
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id, group_id, visibilidad, precio
                FROM mensajes_grupos
                WHERE id = :id AND group_id = :group_id
                LIMIT 1
            ");
            $stmt->execute([':id' => $mensajeId, ':group_id' => $groupId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: false;
        } catch (PDOException $e) {
            error_log("GetMessageForPayment ERROR: " . $e->getMessage());
            return false;
        }
    }

    // Crear mensaje de grupo (texto o archivo, publico o pagado)
    public function createGroupMessage($groupId, $userId, $message, $tipo = 'texto', array $extra = [])
    {
        $stmt = $this->db->prepare("
            INSERT INTO mensajes_grupos
            (
                group_id,
                user_id,
                contenido,
                tipo,
                file_url,
                file_name,
                file_size,
                mime_type,
                latitude,
                longitude,
                visibilidad,
                precio,
                enviado_en
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->execute([
            $groupId,
            $userId,
            $message,
            $tipo,
            $extra['file_url'] ?? null,
            $extra['file_name'] ?? null,
            $extra['file_size'] ?? null,
            $extra['mime_type'] ?? null,
            $extra['latitude'] ?? null,
            $extra['longitude'] ?? null,
            $extra['visibilidad'] ?? 'publico',
            $extra['precio'] ?? null,
        ]);
        return $this->db->lastInsertId();
    }

    // Promover o degradar admin de un grupo
    public function setUserAdminStatus(int $groupId, int $userId, bool $isAdmin): bool
    {
        try {
            $sql = "UPDATE `group_users` SET is_admin = :is_admin WHERE group_id = :group_id AND user_id = :user_id";
            if ($this->hasGroupUsersDeletedAt()) {
                $sql .= " AND deleted_at IS NULL";
            }
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':is_admin' => $isAdmin ? 1 : 0,
                ':group_id' => $groupId,
                ':user_id' => $userId
            ]);
        } catch (PDOException $e) {
            error_log("SetUserAdminStatus ERROR: " . $e->getMessage());
            return false;
        }
    }

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

    // Crear solicitud de ingreso a un grupo privado (sin PIN o PIN incorrecto)
    public function createJoinRequest(int $groupId, int $userId): string
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id FROM group_join_requests WHERE group_id = :group_id AND user_id = :user_id AND status = 'pending' LIMIT 1
            ");
            $stmt->execute([':group_id' => $groupId, ':user_id' => $userId]);
            if ($stmt->fetchColumn()) {
                return 'already_pending';
            }

            $stmt = $this->db->prepare("
                INSERT INTO group_join_requests (group_id, user_id, status, created_at)
                VALUES (:group_id, :user_id, 'pending', NOW())
            ");
            $stmt->execute([':group_id' => $groupId, ':user_id' => $userId]);
            return 'created';
        } catch (PDOException $e) {
            error_log("CreateJoinRequest ERROR: " . $e->getMessage());
            return 'error';
        }
    }

    public function getPendingJoinRequests(int $groupId): array|false
    {
        try {
            $stmt = $this->db->prepare("
                SELECT jr.id, jr.group_id, jr.user_id, jr.created_at, u.name, u.email
                FROM group_join_requests jr
                INNER JOIN users u ON u.id = jr.user_id
                WHERE jr.group_id = :group_id AND jr.status = 'pending'
                ORDER BY jr.created_at ASC
            ");
            $stmt->execute([':group_id' => $groupId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("GetPendingJoinRequests ERROR: " . $e->getMessage());
            return false;
        }
    }

    public function resolveJoinRequest(int $requestId, int $resolverUserId, string $status): bool
    {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("SELECT group_id, user_id FROM group_join_requests WHERE id = :id AND status = 'pending' LIMIT 1 FOR UPDATE");
            $stmt->execute([':id' => $requestId]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$request) {
                $this->db->rollBack();
                return false;
            }

            $stmtUpdate = $this->db->prepare("
                UPDATE group_join_requests
                SET status = :status, resolved_at = NOW(), resolved_by = :resolver
                WHERE id = :id
            ");
            $stmtUpdate->execute([
                ':status' => $status,
                ':resolver' => $resolverUserId,
                ':id' => $requestId
            ]);

            if ($status === 'approved') {
                $stmtAdd = $this->db->prepare("
                    INSERT INTO group_users (group_id, user_id, is_admin, joined_at)
                    VALUES (:group_id, :user_id, 0, NOW())
                    ON DUPLICATE KEY UPDATE left_at = NULL, joined_at = NOW()
                ");
                $stmtAdd->execute([
                    ':group_id' => $request['group_id'],
                    ':user_id' => $request['user_id']
                ]);
            }

            $this->db->commit();
            return true;
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("ResolveJoinRequest ERROR: " . $e->getMessage());
            return false;
        }
    }
}
