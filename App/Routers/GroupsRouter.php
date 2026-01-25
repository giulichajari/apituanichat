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
    fn($req,$res) => $tokenMiddleware->strict(),
    fn($req,$res) => $groupsController->addUsersToGroup()
);
        // Quitar un usuario del grupo
        $router->delete(
            '/group/{idGroup}/user/{idUser}',
            fn() => $tokenMiddleware->strict(),
            fn() => $groupsController->removeUserFromGroup()
        );

        // Listar usuarios de un grupo
        $router->get(
            '/group/{idGroup}/users',
            fn() => $tokenMiddleware->strict(),
            fn() => $groupsController->getGroupUsers()
        );
        // Obtener mensajes del grupo
        $router->get(
            '/group/{idGroup}/messages',
            fn() => $tokenMiddleware->strict(),
            fn() => $groupsController->getGroupMessages()
        );

        // Enviar mensaje al grupo
        $router->post(
            '/group/{idGroup}/messages',
            fn() => $tokenMiddleware->strict(),
            fn() => $groupsController->sendGroupMessage()
        );
    }
}
