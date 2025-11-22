<?php

namespace App\Routers;

use App\Controllers\UsersController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;
use App\Controllers\AuthController;

class UsersRouter
{
    public function __construct(
        ?Router $router,
        ?TokenMiddleware $tokenMiddleware = new TokenMiddleware(),
        ?UsersController $usersController = new UsersController(),
        ?AuthController $authController = new AuthController(),

    ) {

        //First param, the route
        $router->get(
            '/users/page/{page}',
            //This is the first Middleware 
            fn() => $tokenMiddleware->strict(),
            //This is the controller
            fn() => $usersController->getUsers()
        );

        $router->get(
            '/user/{idUser}',
            fn() => $tokenMiddleware->strict(),
            fn() => $usersController->getUser()
        );

        $router->post(
            '/user',
            fn() => $tokenMiddleware->strict(),
            fn() => $usersController->addUser()
        );

        $router->put(
            '/user/{idUser}',
            fn() => $tokenMiddleware->strict(),
            fn() => $usersController->updateUser()
        );

        $router->delete(
            '/user/{idUser}',
            fn() => $tokenMiddleware->strict(),
            fn() => $usersController->deleteUser()
        );
        $router->post("/login", fn() => $authController->login());
        $router->post("/logout", fn() => $authController->logout());
        $router->post("/verify-otp", fn() => $authController->verifyOtp());
        $router->post('/register', function () {
            $controller = new UsersController();
            $controller->register();
        });

        $router->post('/forgot-password', function () {
            $controller = new UsersController();
            $controller->forgotPassword();
        });

        $router->post('/reset-password', function () {
            $controller = new UsersController();
            $controller->resetPassword();
        });
        $router->get('/user/{idUser}/status', function () {
            $controller = new UsersController();
            $controller->status();
        });
    }
}
