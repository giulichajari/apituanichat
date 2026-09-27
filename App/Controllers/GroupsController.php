<?php

namespace App\Controllers;

use App\Models\GroupsModel;
use App\Models\UsersModel;
use App\Models\PrecioGrupoModel;
use App\Models\MensajesPagosModel;
use App\Models\WalletModel;
use App\Models\ReceiptModel;
use App\Services\FileUploadService;
use EasyProjects\SimpleRouter\Router;
use Exception;

class GroupsController
{
    public function __construct(
        private ?GroupsModel $groupsModel = null,
        private ?UsersModel $usersModel = null,
        private ?PrecioGrupoModel $precioGrupoModel = null
    ) {
        $this->groupsModel = $groupsModel ?? new GroupsModel();
        $this->usersModel = $usersModel ?? new UsersModel();
        $this->precioGrupoModel = $precioGrupoModel ?? new PrecioGrupoModel();
    }

    public function addUsersToGroup()
    {
        $data = Router::$request->body;

        $groupId = (int)(
            Router::$request->params->idGroup
            ?? $data->group_id
            ?? 0
        );

        $users = $data->users ?? [];

        if ($groupId <= 0) {
            Router::$response->status(400)->send([
                "message" => "Invalid group_id"
            ]);
            return;
        }

        if (!$this->groupsModel->getGroupById($groupId)) {
            Router::$response->status(404)->send([
                "message" => "Group not found"
            ]);
            return;
        }

        if (empty($users)) {
            Router::$response->status(400)->send([
                "message" => "No users provided"
            ]);
            return;
        }

        foreach ($users as $userId) {
            $added = $this->groupsModel->addUserToGroup(
                $groupId,
                (int)$userId,
                0
            );
            if (!$added) {
                Router::$response->status(500)->send([
                    "message" => "Error adding one or more users to group"
                ]);
                return;
            }
        }

        Router::$response->send([
            "message" => "Users added"
        ]);
    }

    // Listar grupos con paginacion
    public function getGroups($page = 1)
    {
        $page = (int) (Router::$request->params->page ?? $page ?? 1);
        if ($page <= 0) {
            $page = 1;
        }
        $search = Router::$request->query->search ?? null;
        $search = $search ? trim($search) : null;
        $currentUserId = Router::$request->user->id ?? $this->getCurrentUserId();

        $groups = $this->groupsModel->getGroupsByUser($currentUserId, $page, 10, $search);

        if ($groups !== false) {
            Router::$response->status(200)->send([
                "data" => $groups,
                "message" => "Groups listed successfully"
            ]);
        } else {
            Router::$response->status(500)->send([
                "message" => "Error fetching groups"
            ]);
        }
    }

    // Obtener un grupo especifico
    public function getGroup()
    {
        $idGroup = Router::$request->params->idGroup;
        $group = $this->groupsModel->getGroupById($idGroup);

        if ($group) {
            Router::$response->status(200)->send([
                "data" => $group,
                "message" => "Group fetched successfully"
            ]);
        } else {
            Router::$response->status(404)->send([
                "message" => "Group not found"
            ]);
        }
    }

    // Crear un grupo
    public function createGroup()
    {
        $name = Router::$request->body->name ?? null;
        $members = Router::$request->body->members ?? [];
        $isPublic = Router::$request->body->is_public ?? true;
        $joinPin = Router::$request->body->join_pin ?? null;
        if (!$name) {
            return Router::$response->status(400)->send(["message" => "Group name is required"]);
        }
        if ($joinPin !== null && !preg_match('/^\d{4}$/', (string)$joinPin)) {
            return Router::$response->status(400)->send(["message" => "El PIN debe tener exactamente 4 digitos"]);
        }

        $createdBy = Router::$request->user->id ?? $this->getCurrentUserId();
        if (!$createdBy) {
            return Router::$response->status(401)->send(["message" => "Unauthorized"]);
        }

        $groupId = $this->groupsModel->createGroup($createdBy, $name, $members, (bool)$isPublic, $joinPin);

        if ($groupId) {
            $group = $this->groupsModel->getGroupById($groupId);
            Router::$response->status(201)->send([
                "message" => "Group created successfully",
                "group_id" => $groupId,
                "is_public" => (bool)$isPublic,
                "join_pin" => $group['join_pin'] ?? null
            ]);
        } else {
            Router::$response->status(500)->send([
                "message" => "Error creating group"
            ]);
        }
    }

    // Actualizar un grupo (nombre)
    public function updateGroup()
    {
        $idGroup = Router::$request->params->idGroup;
        $name = Router::$request->body->name ?? null;

        if (!$name) {
            return Router::$response->status(400)->send(["message" => "Group name is required"]);
        }

        $updated = $this->groupsModel->updateGroup($idGroup, $name);
        if ($updated) {
            Router::$response->status(200)->send(["message" => "Group updated successfully"]);
        } else {
            Router::$response->status(500)->send(["message" => "Error updating group"]);
        }
    }

    // Activar/desactivar que solo admins puedan chatear (solo el creador del grupo)
    public function updateSoloAdminsChatean()
    {
        $idGroup = (int) (Router::$request->params->idGroup ?? 0);
        $userId = (int) (Router::$request->user->id ?? 0);
        $value = Router::$request->body->solo_admins_chatean ?? null;

        if (!$idGroup || $value === null) {
            Router::$response->status(400)->send(["message" => "Datos invalidos"]);
            return;
        }

        $group = $this->groupsModel->getGroupById($idGroup);
        if (!$group) {
            Router::$response->status(404)->send(["message" => "Group not found"]);
            return;
        }

        if ((int) $group['created_by'] !== $userId) {
            Router::$response->status(403)->send([
                "message" => "Solo el creador del grupo puede cambiar esta configuracion"
            ]);
            return;
        }

        $updated = $this->groupsModel->setSoloAdminsChatean($idGroup, (bool) $value);
        if ($updated) {
            Router::$response->status(200)->send(["message" => "Configuracion actualizada correctamente"]);
        } else {
            Router::$response->status(500)->send(["message" => "Error actualizando configuracion"]);
        }
    }

    // Eliminar un grupo (borrado logico; solo creador o admin)
    public function deleteGroup()
    {
        $idGroup = (int) (Router::$request->params->idGroup ?? 0);
        $userId = (int) (Router::$request->user->id ?? 0);

        if ($idGroup <= 0 || $userId <= 0) {
            Router::$response->status(400)->send(["message" => "Invalid request"]);
            return;
        }

        $result = $this->groupsModel->softDeleteGroup($idGroup, $userId);

        switch ($result) {
            case 'ok':
                Router::$response->status(200)->send([
                    "success" => true,
                    "message" => "Group deleted successfully",
                ]);
                return;
            case 'forbidden':
                Router::$response->status(403)->send([
                    "message" => "Solo el creador del grupo o un administrador pueden eliminarlo",
                ]);
                return;
            case 'not_found':
                Router::$response->status(404)->send(["message" => "Group not found"]);
                return;
            case 'already_deleted':
                Router::$response->status(410)->send(["message" => "This group has already been deleted"]);
                return;
            default:
                Router::$response->status(500)->send([
                    "message" => "Error deleting group. Verify soft-delete migration is applied."
                ]);
        }
    }

    // Descubrir grupos que el usuario todavia no integra
    public function discoverGroups($page = 1)
    {
        $page = (int) (Router::$request->params->page ?? $page ?? 1);
        if ($page <= 0) {
            $page = 1;
        }
        $search = Router::$request->query->search ?? null;
        $search = $search ? trim($search) : null;
        $currentUserId = Router::$request->user->id ?? $this->getCurrentUserId();

        $groups = $this->groupsModel->discoverGroups($currentUserId, $page, 10, $search);

        if ($groups !== false) {
            Router::$response->status(200)->send([
                "data" => $groups,
                "message" => "Groups discovered successfully"
            ]);
        } else {
            Router::$response->status(500)->send([
                "message" => "Error discovering groups"
            ]);
        }
    }

    // El usuario se une a un grupo: publico directo, privado con PIN correcto directo,
    // privado sin PIN o con PIN incorrecto -> queda como solicitud pendiente de aprobacion
    public function joinGroup()
    {
        $idGroup = (int) (Router::$request->params->idGroup ?? 0);
        $pin = Router::$request->body->join_pin ?? null;
        $currentUserId = Router::$request->user->id ?? $this->getCurrentUserId();

        if ($idGroup <= 0 || !$currentUserId) {
            Router::$response->status(400)->send(["message" => "Invalid request"]);
            return;
        }

        $group = $this->groupsModel->getGroupById($idGroup);
        if (empty($group)) {
            Router::$response->status(404)->send(["message" => "Group not found"]);
            return;
        }

        if ($this->groupsModel->isUserInGroup($idGroup, $currentUserId)) {
            Router::$response->status(409)->send(["message" => "Ya sos miembro de este grupo"]);
            return;
        }

        $isPublic = (bool)($group['is_public'] ?? true);

        if ($isPublic) {
            $added = $this->groupsModel->addUserToGroup($idGroup, $currentUserId, false);
            if ($added) {
                Router::$response->status(200)->send([
                    "message" => "Te uniste al grupo correctamente",
                    "status" => "joined"
                ]);
            } else {
                Router::$response->status(500)->send(["message" => "Error al unirse al grupo"]);
            }
            return;
        }

        if ($pin && (string)$pin === (string)($group['join_pin'] ?? '')) {
            $added = $this->groupsModel->addUserToGroup($idGroup, $currentUserId, false);
            if ($added) {
                Router::$response->status(200)->send([
                    "message" => "Te uniste al grupo correctamente",
                    "status" => "joined"
                ]);
            } else {
                Router::$response->status(500)->send(["message" => "Error al unirse al grupo"]);
            }
            return;
        }

        $result = $this->groupsModel->createJoinRequest($idGroup, $currentUserId);
        switch ($result) {
            case 'created':
                Router::$response->status(202)->send([
                    "message" => "Solicitud enviada. Un admin del grupo debe aprobarla.",
                    "status" => "pending"
                ]);
                return;
            case 'already_pending':
                Router::$response->status(200)->send([
                    "message" => "Ya tenes una solicitud pendiente para este grupo",
                    "status" => "pending"
                ]);
                return;
            default:
                Router::$response->status(500)->send(["message" => "Error al procesar la solicitud"]);
                return;
        }
    }

    // Listar solicitudes pendientes de ingreso de un grupo (solo admins)
    public function getPendingJoinRequests()
    {
        $idGroup = (int) (Router::$request->params->idGroup ?? 0);
        $requesterId = (int) (Router::$request->user->id ?? $this->getCurrentUserId());

        if ($idGroup <= 0 || !$requesterId) {
            Router::$response->status(400)->send(["message" => "Invalid request"]);
            return;
        }

        if (!$this->groupsModel->isUserAdmin($idGroup, $requesterId)) {
            Router::$response->status(403)->send(["message" => "Solo un admin del grupo puede ver las solicitudes"]);
            return;
        }

        $requests = $this->groupsModel->getPendingJoinRequests($idGroup);
        if ($requests !== false) {
            Router::$response->status(200)->send([
                "data" => $requests,
                "message" => "Pending join requests listed successfully"
            ]);
        } else {
            Router::$response->status(500)->send(["message" => "Error fetching join requests"]);
        }
    }

    // Aprobar o rechazar una solicitud de ingreso (solo admins)
    public function resolveJoinRequest()
    {
        $idGroup = (int) (Router::$request->params->idGroup ?? 0);
        $idRequest = (int) (Router::$request->params->idRequest ?? 0);
        $resolverUserId = (int) (Router::$request->user->id ?? $this->getCurrentUserId());
        $status = Router::$request->body->status ?? null;

        if ($idGroup <= 0 || $idRequest <= 0 || !$resolverUserId || !in_array($status, ['approved', 'rejected'], true)) {
            Router::$response->status(400)->send(["message" => "Invalid request"]);
            return;
        }

        if (!$this->groupsModel->isUserAdmin($idGroup, $resolverUserId)) {
            Router::$response->status(403)->send(["message" => "Solo un admin del grupo puede resolver solicitudes"]);
            return;
        }

        $ok = $this->groupsModel->resolveJoinRequest($idRequest, $resolverUserId, $status);
        if ($ok) {
            Router::$response->status(200)->send([
                "message" => $status === 'approved' ? "Solicitud aprobada" : "Solicitud rechazada"
            ]);
        } else {
            Router::$response->status(500)->send(["message" => "Error al resolver la solicitud"]);
        }
    }

    // Agregar usuario al grupo (por un admin)
    public function addUserToGroup()
    {
        $idGroup = Router::$request->params->idGroup;
        $idUser = Router::$request->params->idUser;
        $isAdmin = Router::$request->body->isAdmin ?? false;

        $requesterId = Router::$request->user->id ?? null;
        if (!$requesterId) {
            Router::$response->status(401)->send(["message" => "No autenticado"]);
            return;
        }

        if (!$this->groupsModel->isUserAdmin($idGroup, $requesterId)) {
            Router::$response->status(403)->send(["message" => "Solo un admin del grupo puede agregar usuarios"]);
            return;
        }

        $added = $this->groupsModel->addUserToGroup($idGroup, $idUser, $isAdmin);
        if ($added) {
            Router::$response->status(200)->send(["message" => "User added to group successfully"]);
        } else {
            Router::$response->status(500)->send(["message" => "Error adding user to group"]);
        }
    }

    // Quitar usuario del grupo
    public function removeUserFromGroup()
    {
        $idGroup = (int) (Router::$request->params->idGroup ?? 0);
        $targetUserId = (int) (Router::$request->params->idUser ?? 0);
        $actorUserId = (int) (Router::$request->user->id ?? 0);

        if ($idGroup <= 0 || $targetUserId <= 0 || $actorUserId <= 0) {
            Router::$response->status(400)->send(["message" => "Invalid request"]);
            return;
        }

        $group = $this->groupsModel->getGroupById($idGroup);
        if (empty($group)) {
            Router::$response->status(404)->send(["message" => "Group not found"]);
            return;
        }

        $isCreator = ((int) ($group['created_by'] ?? 0)) === $actorUserId;
        $isSelfLeave = $actorUserId === $targetUserId;

        if (!$isSelfLeave && !$isCreator) {
            Router::$response->status(403)->send([
                "message" => "Only the group creator can remove other members"
            ]);
            return;
        }

        if (!$this->groupsModel->isUserInGroup($idGroup, $targetUserId)) {
            Router::$response->status(404)->send(["message" => "User is not in this group"]);
            return;
        }

        $removed = $this->groupsModel->removeUserFromGroup($idGroup, $targetUserId);
        if (!$removed) {
            Router::$response->status(500)->send(["message" => "Error removing user from group"]);
            return;
        }

        Router::$response->status(200)->send([
            "message" => $isSelfLeave
                ? "You have left the group successfully"
                : "User removed from group successfully"
        ]);
    }

    // Promover o degradar admin de un miembro del grupo (solo el creador)
    public function setUserAdminStatus()
    {
        $idGroup = (int) (Router::$request->params->idGroup ?? 0);
        $targetUserId = (int) (Router::$request->params->idUser ?? 0);
        $actorUserId = (int) (Router::$request->user->id ?? 0);
        $isAdmin = (bool) (Router::$request->body->isAdmin ?? false);
        if ($idGroup <= 0 || $targetUserId <= 0 || $actorUserId <= 0) {
            Router::$response->status(400)->send(["message" => "Invalid request"]);
            return;
        }
        $group = $this->groupsModel->getGroupById($idGroup);
        if (empty($group)) {
            Router::$response->status(404)->send(["message" => "Group not found"]);
            return;
        }
        $isCreator = ((int) ($group['created_by'] ?? 0)) === $actorUserId;
        if (!$isCreator) {
            Router::$response->status(403)->send([
                "message" => "Only the group creator can change admin status"
            ]);
            return;
        }
        if ($targetUserId === $actorUserId && !$isAdmin) {
            Router::$response->status(403)->send([
                "message" => "The group creator cannot be demoted"
            ]);
            return;
        }
        if (!$this->groupsModel->isUserInGroup($idGroup, $targetUserId)) {
            Router::$response->status(404)->send(["message" => "User is not in this group"]);
            return;
        }
        $updated = $this->groupsModel->setUserAdminStatus($idGroup, $targetUserId, $isAdmin);
        if (!$updated) {
            Router::$response->status(500)->send(["message" => "Error updating admin status"]);
            return;
        }
        Router::$response->status(200)->send([
            "message" => $isAdmin
                ? "User promoted to admin successfully"
                : "User demoted from admin successfully"
        ]);
    }

    // Listar usuarios de un grupo
    public function getGroupUsers()
    {
        $idGroup = Router::$request->params->idGroup;
        $users = $this->groupsModel->getUsersByGroup($idGroup);

        if ($users !== false) {
            Router::$response->status(200)->send([
                "data" => $users,
                "message" => "Group users listed successfully"
            ]);
        } else {
            Router::$response->status(500)->send([
                "message" => "Error fetching group users"
            ]);
        }
    }

    public function getGroupMessages()
    {
        $groupId = (int) (Router::$request->params->idGroup ?? 0);
        $userId  = (int) (Router::$request->user->id ?? 0);

        if ($groupId <= 0 || $userId <= 0) {
            Router::$response->status(400)->send(["message" => "Parametros invalidos"]);
            return;
        }

        if (!$this->groupsModel->isUserInGroup($groupId, $userId)) {
            Router::$response->status(403)->send([
                "message" => "No perteneces a este grupo o el grupo ya no esta disponible"
            ]);
            return;
        }

        $messages = $this->groupsModel->getGroupMessages($groupId, $userId);

        Router::$response->status(200)->send([
            "data" => $messages,
            "message" => "Messages fetched successfully"
        ]);
    }

    // Enviar mensaje de texto (o registrar visibilidad/precio) al grupo
    public function sendGroupMessage()
    {
        $body = Router::$request->body;

        $groupId = $body->group_id ?? null;
        $userId  = Router::$request->user->id;

        $message = trim($body->message ?? '');
        $tipo    = $body->tipo ?? 'texto';
        $visibilidad = ($body->visibilidad ?? 'publico') === 'privado' ? 'privado' : 'publico';
        $precio = isset($body->precio) ? (float) $body->precio : null;

        if (!$groupId) {
            Router::$response->status(400)->send([
                "message" => "Group ID requerido"
            ]);
            return;
        }

        if (!$message && $tipo === 'texto') {
            Router::$response->status(400)->send([
                "message" => "El mensaje no puede estar vacio"
            ]);
            return;
        }

        if (!$this->groupsModel->isUserInGroup($groupId, $userId)) {
            Router::$response->status(403)->send([
                "message" => "No perteneces a este grupo"
            ]);
            return;
        }

        if (!$this->groupsModel->canUserSendGroupMessage($groupId, $userId)) {
            Router::$response->status(403)->send([
                "message" => "Solo los administradores pueden enviar mensajes en este grupo"
            ]);
            return;
        }

        if ($visibilidad === 'privado' && !$this->precioGrupoModel->isMontoValido((float) $precio)) {
            Router::$response->status(400)->send([
                "message" => "Precio invalido para mensaje privado"
            ]);
            return;
        }

        $saved = $this->groupsModel->createGroupMessage(
            $groupId,
            $userId,
            $message,
            $tipo,
            [
                'visibilidad' => $visibilidad,
                'precio' => $visibilidad === 'privado' ? $precio : null,
            ]
        );

        if ($saved) {
            Router::$response->status(201)->send([
                "message" => "Mensaje enviado correctamente"
            ]);
        } else {
            Router::$response->status(500)->send([
                "message" => "Error al guardar mensaje"
            ]);
        }
    }

    // Subir archivo como mensaje de grupo (publico o pagado)
    // Lista los montos activos permitidos para archivos/mensajes pagados de grupo
    public function getPreciosGrupo()
    {
        $precios = $this->precioGrupoModel->getPreciosActivos();
        Router::$response->status(200)->send([
            "data" => $precios,
            "message" => "Precios listados correctamente"
        ]);
    }

    // Crea (o reutiliza) el link de pago de Square para desbloquear un mensaje privado de grupo
    public function createGroupMessagePayment()
    {
        $this->payGroupMessageWithWallet();
    }

    public function payGroupMessageWithWallet()
    {
        $groupId = (int) (Router::$request->params->idGroup ?? 0);
        $messageId = (int) (Router::$request->params->idMessage ?? 0);
        $userId = (int) (Router::$request->user->id ?? 0);
        if (!$userId || !$this->groupsModel->isUserInGroup($groupId, $userId)) {
            Router::$response->status(403)->send(['message' => 'No tienes acceso a este grupo.']); return;
        }
        $message = $this->groupsModel->getMessageForPayment($messageId, $groupId);
        if (!$message || $message['visibilidad'] !== 'privado' || empty($message['precio'])) {
            Router::$response->status(400)->send(['message' => 'El mensaje no requiere pago o no existe.']); return;
        }
        try {
            $amount = (float) \App\Services\UsdMoney::decimal(\App\Services\UsdMoney::cents($message['precio']));
            $checkout = new \App\Services\WalletCheckout();
            $result = $checkout->run($userId, 'group-message', (string) $messageId, ['message' => $messageId], function () use ($message, $userId, $messageId, $amount, $groupId) {
                $db = \App\Configs\Database::getInstance()->getConnection();
                $lock = $db->prepare('SELECT id, user_id, group_id, visibilidad, precio FROM mensajes_grupos WHERE id = ? AND group_id = ? FOR UPDATE');
                $lock->execute([$messageId, $groupId]);$message = $lock->fetch(\PDO::FETCH_ASSOC);
                if (!$message || !$this->groupsModel->isUserInGroup($groupId, $userId) || $message['visibilidad'] !== 'privado') throw new \DomainException('Contenido no disponible');
                if (\App\Services\UsdMoney::cents($message['precio']) !== \App\Services\UsdMoney::cents($amount)) throw new \DomainException('El precio cambió; actualiza el contenido');
                $db = \App\Configs\Database::getInstance()->getConnection();
                $stmt = $db->prepare("SELECT id FROM mensajes_pagos WHERE mensaje_id = ? AND usuario_id = ? AND estado = 'completado' LIMIT 1");
                $stmt->execute([$messageId, $userId]);
                if ($id = $stmt->fetchColumn()) return ['paid' => true, 'alreadyPaid' => true, 'pago_id' => (int) $id];
                $debit = (new WalletModel())->debitForPurchase($userId, $amount, 'group_message_' . $messageId);
                if (!$debit['success']) throw new \DomainException($debit['message']);
                $id = (new MensajesPagosModel())->createWalletPayment($messageId, $userId, $amount);
                if (!$id) throw new \RuntimeException('No se pudo desbloquear el mensaje');
                (new \App\Services\CommerceSettlement())->hold('group_message_' . $id, $debit['transaction_id'], (int) $message['user_id']);
                (new \App\Services\CommerceSettlement())->resolve('group_message_' . $id, 'release', $userId, 'Entrega digital confirmada');
                return ['paid' => true, 'pago_id' => $id, 'new_balance' => $debit['new_balance']];
            });
            if (empty($result['replayed']) && empty($result['alreadyPaid'])) {
                try { (new ReceiptModel())->createAndNotify($userId, 'group_message', $messageId, $amount, 'wallet', 'Mensaje privado desbloqueado en un grupo'); }
                catch (\Throwable $e) { error_log('Group receipt pending: ' . $e->getMessage()); }
            }
            Router::$response->status(200)->send($result + ['message' => 'Mensaje desbloqueado con Wallet.']);
        } catch (\Throwable $e) {
            error_log('Group Wallet checkout: ' . $e->getMessage());
            Router::$response->status($e instanceof \DomainException ? 402 : 503)->send(['message' => $e instanceof \DomainException ? $e->getMessage() : 'No se completó el pago. Puedes reintentar.']);
        }
    }

    public function uploadGroupFile()
    {
        $groupId = (int) (Router::$request->params->idGroup ?? 0);
        $userId  = (int) (Router::$request->user->id ?? 0);

        if (!$groupId) {
            Router::$response->status(400)->send([
                "message" => "Group ID requerido"
            ]);
            return;
        }

        if (!$this->groupsModel->isUserInGroup($groupId, $userId)) {
            Router::$response->status(403)->send([
                "message" => "No perteneces a este grupo"
            ]);
            return;
        }

        if (!$this->groupsModel->canUserSendGroupMessage($groupId, $userId)) {
            Router::$response->status(403)->send([
                "message" => "Solo los administradores pueden enviar mensajes en este grupo"
            ]);
            return;
        }

        if (!isset($_FILES['file'])) {
            Router::$response->status(400)->send([
                "message" => "No se recibio ningun archivo"
            ]);
            return;
        }

        $visibilidad = (($_POST['visibilidad'] ?? 'publico') === 'privado') ? 'privado' : 'publico';
        $precio = isset($_POST['precio']) ? (float) $_POST['precio'] : null;

        if ($visibilidad === 'privado' && !$this->precioGrupoModel->isMontoValido((float) $precio)) {
            Router::$response->status(400)->send([
                "message" => "Precio invalido para archivo privado"
            ]);
            return;
        }

        try {
            $fileUploadService = new FileUploadService();
            $result = $fileUploadService->uploadGroupFileSimple(
                $_FILES['file'],
                $groupId,
                $userId,
                [
                    'visibilidad' => $visibilidad,
                    'precio' => $visibilidad === 'privado' ? $precio : null,
                ]
            );

            if (!$result['success']) {
                Router::$response->status(500)->send([
                    "message" => $result['message']
                ]);
                return;
            }

            Router::$response->status(201)->send([
                "message" => "Archivo enviado correctamente",
                "data" => $result
            ]);
        } catch (\Exception $e) {
            Router::$response->status(500)->send([
                "message" => "Error interno: " . 'No se pudo completar la operación'
            ]);
        }
    }

    private function getCurrentUserId()
    {
        $headers = apache_request_headers();
        $token = str_replace('Bearer ', '', $headers['Authorization'] ?? '');
        if ($token) {
            $payload = json_decode(base64_decode(explode('.', $token)[1]), true);
            return $payload['user_id'] ?? null;
        }
        return null;
    }
}
