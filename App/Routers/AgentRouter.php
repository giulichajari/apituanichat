<?php

namespace App\Routers;

use App\Controllers\AgentController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;

class AgentRouter
{
    public function __construct(
        ?Router $router,
        ?TokenMiddleware $tokenMiddleware = new TokenMiddleware(),
        ?AgentController $agentController = new AgentController(),
    ) {
        $router->get(
            '/agents',
            fn() => $tokenMiddleware->strict(),
            fn() => $agentController->listMyAgents()
        );

        $router->post(
            '/agents',
            fn() => $tokenMiddleware->strict(),
            fn() => $agentController->createAgent()
        );

        $router->patch(
            '/agents/{agent_id}',
            fn() => $tokenMiddleware->strict(),
            fn() => $agentController->updateAgent()
        );

        $router->post(
            '/agents/{agent_id}/regenerate-secret',
            fn() => $tokenMiddleware->strict(),
            fn() => $agentController->regenerateAgentSecret()
        );

        $router->delete(
            '/agents/{agent_id}',
            fn() => $tokenMiddleware->strict(),
            fn() => $agentController->deleteAgent()
        );
    }
}
