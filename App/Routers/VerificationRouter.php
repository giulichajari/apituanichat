<?php
namespace App\Routers;

use App\Controllers\VerificationController;
use App\Controllers\PaymentController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;

class VerificationRouter
{
    public function __construct(
        ?Router $router,
        ?TokenMiddleware $tokenMiddleware = new TokenMiddleware(),
        ?VerificationController $verificationController = new VerificationController(),
        ?PaymentController $paymentController = new PaymentController()
    ) {
        $router->post(
            '/verification-request',
            fn() => $tokenMiddleware->strict(),
            fn() => $verificationController->submitRequest()
        );
        $router->get(
            '/verification-request/status',
            fn() => $tokenMiddleware->strict(),
            fn() => $verificationController->getMyStatus()
        );
        $router->get(
            '/admin/verification-requests',
            fn() => $tokenMiddleware->strict(),
            fn() => $verificationController->listPending()
        );
        $router->post(
            '/admin/verification-requests/{id}/approve',
            fn() => $tokenMiddleware->strict(),
            fn() => $verificationController->approve()
        );
        $router->post(
            '/admin/verification-requests/{id}/reject',
            fn() => $tokenMiddleware->strict(),
            fn() => $verificationController->reject()
        );
        $router->post(
            '/verification-payment',
            fn() => $tokenMiddleware->strict(),
            fn() => $paymentController->createVerificationPaymentLink()
        );
        $router->get(
            '/verification-payment/status',
            fn() => $tokenMiddleware->strict(),
            fn() => $paymentController->getVerificationPaymentStatus()
        );
        $router->get(
            '/admin/users/search',
            fn() => $tokenMiddleware->strict(),
            fn() => $verificationController->searchUsers()
        );
        $router->post(
            '/admin/users/{id}/force-verify',
            fn() => $tokenMiddleware->strict(),
            fn() => $verificationController->forceVerify()
        );
        $router->post(
            '/admin/users/{id}/unverify',
            fn() => $tokenMiddleware->strict(),
            fn() => $verificationController->unverify()
        );
    }
}
