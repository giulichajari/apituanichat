<?php

namespace App\Routers;

use App\Controllers\GroupsController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;

class GroupsRouter
{
    public function __construct(
        ?Router $router,
        ?TokenMiddleware $tokenMiddleware = null,
        ?GroupsController $groupsController = null
    ) {
        $tokenMiddleware = $tokenMiddleware ?? new TokenMiddleware();
        $groupsController = $groupsController ?? new GroupsController();

        // Listar todos los grupos (paginado)
        $router->get(
            '/groups/page/{page}',
            fn() => $tokenMiddleware->strict(),
            fn() => $groupsController->getGroups()
        );

        // Descubrir grupos que el usuario todavia no integra
        $router->get(
            '/groups/discover/{page}',
            fn() => $tokenMiddleware->strict(),
            fn() => $groupsController->discoverGroups()
        );

        // Unirse a un grupo por cuenta propia (publico directo, privado con PIN o solicitud)
        $router->post(
            '/group/{idGroup}/join',
            fn() => $tokenMiddleware->strict(),
            fn() => $groupsController->joinGroup()
        );

        // Listar solicitudes de ingreso pendientes de un grupo (admins)
        $router->get(
            '/group/{idGroup}/join-requests',
            fn() => $tokenMiddleware->strict(),
            fn() => $groupsController->getPendingJoinRequests()
        );

        // Aprobar o rechazar una solicitud de ingreso (admins)
        $router->put(
            '/group/{idGroup}/join-requests/{idRequest}',
            fn() => $tokenMiddleware->strict(),
            fn() => $groupsController->resolveJoinRequest()
        );

        // Obtener un grupo por ID
        $router->get(
            '/group/{idGroup}',
            fn() => $tokenMiddleware->strict(),
            fn() => $groupsController->getGroup()
        );

        // Crear un nuevo grupo
        $router->post(
            '/group',
            fn() => $tokenMiddleware->strict(),
            fn() => $groupsController->createGroup()
        );

        // Actualizar un grupo (nombre, admin, etc)
        $router->put(
            '/group/{idGroup}',
            fn() => $tokenMiddleware->strict(),
            fn() => $groupsController->updateGroup()
        );

        // Activar/desactivar que solo admins puedan chatear en el grupo (solo creador)
        $router->put(
            '/group/{idGroup}/solo-admins-chatean',
            fn() => $tokenMiddleware->strict(),
            fn() => $groupsController->updateSoloAdminsChatean()
        );

        // Eliminar un grupo
        $router->delete(
            '/group/{idGroup}',
            fn() => $tokenMiddleware->strict(),
            fn() => $groupsController->deleteGroup()
        );

        // Agregar un usuario al grupo
        $router->post(
            '/group/{idGroup}/user/{idUser}',
            fn() => $tokenMiddleware->strict(),
            fn() => $groupsController->addUserToGroup()
        );
        $router->post(
            '/group/{idGroup}/users',
            fn($req, $res) => $tokenMiddleware->strict(),
            fn($req, $res) => $groupsController->addUsersToGroup()
        );
        // Quitar un usuario del grupo
        $router->delete(
            '/group/{idGroup}/user/{idUser}',
            fn() => $tokenMiddleware->strict(),
            fn() => $groupsController->removeUserFromGroup()
        );

        // Promover o degradar admin de un miembro del grupo
        $router->put(
            '/group/{idGroup}/user/{idUser}/admin',
            fn() => $tokenMiddleware->strict(),
            fn() => $groupsController->setUserAdminStatus()
        );

        // Listar usuarios de un grupo
        $router->get(
            '/group/{idGroup}/users',
            fn() => $tokenMiddleware->strict(),
            fn() => $groupsController->getGroupUsers()
        );
        // Obtener mensajes del grupo
        $router->get(
            '/group/messages/{idGroup}',
            fn() => $tokenMiddleware->strict(),
            fn() => $groupsController->getGroupMessages()
        );

        // Enviar mensaje al grupo
        $router->post(
            '/group/{idGroup}/messages',
            fn() => $tokenMiddleware->strict(),
            fn() => $groupsController->sendGroupMessage()
        );

        // Listar precios activos para archivos/mensajes pagados de grupo
        $router->get(
            '/groups/precios-config',
            fn() => $tokenMiddleware->strict(),
            fn() => $groupsController->getPreciosGrupo()
        );

        // Crear link de pago de Square para desbloquear un mensaje privado de grupo
        $router->post(
            '/group/{idGroup}/messages/{idMessage}/pay',
            fn() => $tokenMiddleware->strict(),
            fn() => $groupsController->createGroupMessagePayment()
        );

        // Pagar y desbloquear un mensaje privado de grupo con el wallet interno
        $router->post(
            '/group/{idGroup}/messages/{idMessage}/pay-wallet',
            fn() => $tokenMiddleware->strict(),
            fn() => $groupsController->payGroupMessageWithWallet()
        );

        // Subir archivo como mensaje de grupo (publico o pagado)
        $router->post(
            '/group/{idGroup}/upload',
            fn() => $tokenMiddleware->strict(),
            fn() => $groupsController->uploadGroupFile()
        );
    }
}
