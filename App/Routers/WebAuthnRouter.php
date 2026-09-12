<?php
namespace App\Routers;

use App\Controllers\WebAuthnController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;

class WebAuthnRouter
{
    public function __construct(
        ?Router $router,
        ?TokenMiddleware $tokenMiddleware = new TokenMiddleware(),
        ?WebAuthnController $webAuthnController = new WebAuthnController()
    ) {
        $router->get(
            '/webauthn/status',
            fn() => $tokenMiddleware->strict(),
            fn() => $webAuthnController->status()
        );
        $router->get(
            '/webauthn/register-options',
            fn() => $tokenMiddleware->strict(),
            fn() => $webAuthnController->registerOptions()
        );
        $router->post(
            '/webauthn/register-verify',
            fn() => $tokenMiddleware->strict(),
            fn() => $webAuthnController->registerVerify()
        );
        $router->get(
            '/webauthn/auth-options',
            fn() => $tokenMiddleware->strict(),
            fn() => $webAuthnController->authOptions()
        );
        $router->post(
            '/webauthn/verify',
            fn() => $tokenMiddleware->strict(),
            fn() => $webAuthnController->verify()
        );
        $router->delete(
            '/webauthn/{id}',
            fn() => $tokenMiddleware->strict(),
            fn($id) => $webAuthnController->deleteCredential($id)
        );
    }
}
