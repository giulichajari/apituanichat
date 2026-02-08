<?php

namespace App\Controllers;

use App\Models\GroupsModel;
use App\Models\UsersModel;
use EasyProjects\SimpleRouter\Router;
use Exception;

class GroupsController
{
    public function __construct(
        private ?GroupsModel $groupsModel = null,
        private ?UsersModel $usersModel = null
    ) {
        $this->groupsModel = $groupsModel ?? new GroupsModel();
        $this->usersModel = $usersModel ?? new UsersModel();
    }
    public function addUsersToGroup()
    {
        $data = Router::$request->body;

        // Primero URL, si no hay, body
        $groupId = (int)(
            Router::$request->params['idGroup']
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

        if (empty($users)) {
            Router::$response->status(400)->send([
                "message" => "No users provided"
            ]);
            return;
        }

        foreach ($users as $userId) {
            $this->groupsModel->addUserToGroup(
                $groupId,
                (int)$userId,
                0
            );
        }

        Router::$response->send([
            "message" => "Users added"
        ]);
    }


    // Listar grupos con paginación
    public function getGroups($page = 1)
    {
        $page = intval($page) ?: 1;
        $currentUserId = Router::$request->user->id ?? $this->getCurrentUserId();

        $groups = $this->groupsModel->getGroupsByUser($currentUserId, $page, 10);

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

    // Obtener un grupo específico
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
        $members = Router::$request->body->members ?? []; // Array de IDs de usuarios
        if (!$name) {
            return Router::$response->status(400)->send(["message" => "Group name is required"]);
        }

        $createdBy = Router::$request->user->id ?? $this->getCurrentUserId();
        if (!$createdBy) {
            return Router::$response->status(401)->send(["message" => "Unauthorized"]);
        }

        // Crear grupo con el creador y los miembros seleccionados
        $groupId = $this->groupsModel->createGroup($createdBy, $name, $members);

        if ($groupId) {
            Router::$response->status(201)->send([
                "message" => "Group created successfully",
                "group_id" => $groupId
            ]);
        } else {
            Router::$response->status(500)->send([
                "message" => "Error creating group"
            ]);
        }
    }

    public function getGroupMessages()
    {
        $groupId = Router::$request->params->idGroup;
        $userId  = Router::$request->user->id;



        $messages = $this->groupsModel->getGroupMessages($groupId, $userId);

        Router::$response->status(200)->send([
            "data" => $messages,
            "message" => "Messages fetched successfully"
        ]);
    }
    public function sendGroupMessage()
{
    $body = Router::$request->body;

    $groupId = $body->group_id ?? null;
    $userId  = Router::$request->user->id;

    $message = trim($body->message ?? '');
    $tipo    = $body->tipo ?? 'texto';


    if (!$groupId) {
        Router::$response->status(400)->send([
            "message" => "Group ID requerido"
        ]);
        return;
    }

    if (!$message && $tipo === 'texto') {
        Router::$response->status(400)->send([
            "message" => "El mensaje no puede estar vacío"
        ]);
        return;
    }


    if (!$this->groupsModel->isUserInGroup($groupId, $userId)) {
        Router::$response->status(403)->send([
            "message" => "No perteneces a este grupo"
        ]);
        return;
    }


    $saved = $this->groupsModel->createGroupMessage(
        $groupId,
        $userId,
        $message,
        $tipo
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

    // Eliminar un grupo
    public function deleteGroup()
    {
        $idGroup = Router::$request->params->idGroup;
        $deleted = $this->groupsModel->deleteGroup($idGroup);

        if ($deleted) {
            Router::$response->status(200)->send(["message" => "Group deleted successfully"]);
        } else {
            Router::$response->status(500)->send(["message" => "Error deleting group"]);
        }
    }

    // Agregar usuario al grupo
    public function addUserToGroup()
    {
        $idGroup = Router::$request->params->idGroup;
        $idUser = Router::$request->params->idUser;
        $isAdmin = Router::$request->body->isAdmin ?? false;

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
        $idGroup = Router::$request->params->idGroup;
        $idUser = Router::$request->params->idUser;

        $removed = $this->groupsModel->removeUserFromGroup($idGroup, $idUser);
        if ($removed) {
            Router::$response->status(200)->send(["message" => "User removed from group successfully"]);
        } else {
            Router::$response->status(500)->send(["message" => "Error removing user from group"]);
        }
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

    // Obtener ID del usuario actual desde token
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
