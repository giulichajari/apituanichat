<?php
namespace App\Routers;

use App\Controllers\AdminWalletController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;

class AdminWalletRouter
{
    public function __construct(
        ?Router $router,
        ?TokenMiddleware $tokenMiddleware = new TokenMiddleware(),
        ?AdminWalletController $adminWalletController = new AdminWalletController()
    ) {
        $router->get(
            '/admin/wallet/overview',
            fn() => $tokenMiddleware->strict(),
            fn() => $adminWalletController->overview()
        );
        $router->get(
            '/admin/wallet/users',
            fn() => $tokenMiddleware->strict(),
            fn() => $adminWalletController->listWallets()
        );
        $router->get(
            '/admin/wallet/transactions',
            fn() => $tokenMiddleware->strict(),
            fn() => $adminWalletController->listTransactions()
        );
        $router->patch(
            '/admin/wallet/{userId}/status',
            fn() => $tokenMiddleware->strict(),
            fn($userId) => $adminWalletController->setStatus($userId)
        );
        $router->post(
            '/admin/wallet/{userId}/adjust',
            fn() => $tokenMiddleware->strict(),
            fn($userId) => $adminWalletController->adjustBalance($userId)
        );
        $router->get(
            '/admin/wallet/alerts',
            fn() => $tokenMiddleware->strict(),
            fn() => $adminWalletController->listAlerts()
        );
        $router->patch(
            '/admin/wallet/alerts/{id}/resolve',
            fn() => $tokenMiddleware->strict(),
            fn($id) => $adminWalletController->resolveAlert($id)
        );
    }
}
