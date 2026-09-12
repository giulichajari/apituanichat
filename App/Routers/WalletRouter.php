<?php
namespace App\Routers;

use App\Controllers\WalletController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;

class WalletRouter
{
    public function __construct(
        ?Router $router,
        ?TokenMiddleware $tokenMiddleware = new TokenMiddleware(),
        ?WalletController $walletController = new WalletController()
    ) {
        $router->get(
            '/wallet',
            fn() => $tokenMiddleware->strict(),
            fn() => $walletController->getWallet()
        );
        $router->post(
            '/wallet/recharge',
            fn() => $tokenMiddleware->strict(),
            fn() => $walletController->createRecharge()
        );
        $router->get(
            '/wallet/pin',
            fn() => $tokenMiddleware->strict(),
            fn() => $walletController->getPinStatus()
        );
        $router->post(
            '/wallet/pin',
            fn() => $tokenMiddleware->strict(),
            fn() => $walletController->setPin()
        );
        $router->delete(
            '/wallet/pin',
            fn() => $tokenMiddleware->strict(),
            fn() => $walletController->disablePin()
        );
        $router->post(
            '/wallet/recharge-with-card',
            fn() => $tokenMiddleware->strict(),
            fn() => $walletController->rechargeWithSavedCard()
        );
        $router->post(
            '/wallet/transfer',
            fn() => $tokenMiddleware->strict(),
            fn() => $walletController->transfer()
        );
        $router->get(
            '/wallet/transactions',
            fn() => $tokenMiddleware->strict(),
            fn() => $walletController->getTransactions()
        );
        $router->post(
            '/wallet/pay',
            fn() => $tokenMiddleware->strict(),
            fn() => $walletController->payWithWallet()
        );
    }
}
