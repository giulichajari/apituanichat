<?php
namespace App\Routers;

use App\Controllers\LiveController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;

class LiveRouter
{
    public function __construct(
        ?Router $router,
        ?TokenMiddleware $tokenMiddleware = new TokenMiddleware(),
        ?LiveController $liveController = new LiveController()
    ) {
        $router->post(
            '/groups/{idGroup}/live/start',
            fn() => $tokenMiddleware->strict(),
            fn() => $liveController->startLive()
        );

        $router->get(
            '/groups/{idGroup}/live/current',
            fn() => $tokenMiddleware->strict(),
            fn() => $liveController->getCurrentLive()
        );

        $router->post('/live/on-publish', fn() => $liveController->onPublish());
        $router->post('/live/on-publish-done', fn() => $liveController->onPublishDone());

        $router->post(
            '/live/{postId}/heartbeat',
            fn() => $tokenMiddleware->strict(),
            fn() => $liveController->heartbeat()
        );

        $router->post(
            '/live/{postId}/leave',
            fn() => $tokenMiddleware->strict(),
            fn() => $liveController->leaveLive()
        );

        $router->get(
            '/live/{postId}/summary',
            fn() => $tokenMiddleware->strict(),
            fn() => $liveController->getSummary()
        );

        $router->get(
            '/live/{postId}/viewers',
            fn() => $tokenMiddleware->strict(),
            fn() => $liveController->getViewers()
        );

        $router->post(
            '/live/{postId}/kick/{userId}',
            fn() => $tokenMiddleware->strict(),
            fn() => $liveController->kickViewer()
        );

        $router->post(
            '/live/{postId}/block/{userId}',
            fn() => $tokenMiddleware->strict(),
            fn() => $liveController->blockViewer()
        );

        $router->post(
            '/live/{postId}/gift',
            fn() => $tokenMiddleware->strict(),
            fn() => $liveController->sendGift()
        );

        $router->get(
            '/live/gift/{giftId}/status',
            fn() => $tokenMiddleware->strict(),
            fn() => $liveController->getGiftStatus()
        );

        $router->get(
            '/live/{postId}/points',
            fn() => $tokenMiddleware->strict(),
            fn() => $liveController->getLivePoints()
        );

        $router->get(
            '/live/{postId}/gifts/new',
            fn() => $tokenMiddleware->strict(),
            fn() => $liveController->getNewGifts()
        );
        $router->post(
            '/live/{postId}/recording',
            fn() => $tokenMiddleware->strict(),
            fn() => $liveController->uploadRecording()
        );
        $router->get(
            '/groups/{idGroup}/live/recordings',
            fn() => $tokenMiddleware->strict(),
            fn() => $liveController->getRecordings()
        );
        $router->post(
            '/live/{postId}/chat',
            fn() => $tokenMiddleware->strict(),
            fn() => $liveController->sendChatMessage()
        );
        $router->post(
            '/live/{postId}/chat/pin',
            fn() => $tokenMiddleware->strict(),
            fn() => $liveController->sendPinnedMessage()
        );
        $router->get(
            '/live/{postId}/chat/new',
            fn() => $tokenMiddleware->strict(),
            fn() => $liveController->getChatMessages()
        );
        $router->get(
            '/live/{postId}/chat/pinned',
            fn() => $tokenMiddleware->strict(),
            fn() => $liveController->getPinnedMessages()
        );
    }
}
