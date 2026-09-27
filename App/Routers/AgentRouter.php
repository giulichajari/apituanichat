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
        $activation = new \App\Controllers\EnterpriseAgentActivationController();
        $router->get('/enterprise/companies/{tenant_id}/agent-activation', fn() => $tokenMiddleware->strict(), fn() => $activation->handle());
        $router->post('/enterprise/companies/{tenant_id}/agent-activation', fn() => $tokenMiddleware->strict(), fn() => $activation->handle(true));
        $recordProposals = new \App\Controllers\EnterpriseRecordProposalsController();
        $router->get('/enterprise/companies/{tenant_id}/record-proposals', fn() => $tokenMiddleware->strict(), fn() => $recordProposals->handle());
        $router->post('/enterprise/companies/{tenant_id}/record-proposals', fn() => $tokenMiddleware->strict(), fn() => $recordProposals->handle('generate'));
        $router->post('/enterprise/companies/{tenant_id}/record-proposals/accept', fn() => $tokenMiddleware->strict(), fn() => $recordProposals->handle('accept'));
        $recordJobs = new \App\Controllers\EnterpriseRecordJobsController();
        $router->get('/enterprise/companies/{tenant_id}/record-jobs', fn() => $tokenMiddleware->strict(), fn() => $recordJobs->handle());
        $router->post('/enterprise/companies/{tenant_id}/record-jobs', fn() => $tokenMiddleware->strict(), fn() => $recordJobs->handle(true));
        $records = new \App\Controllers\EnterpriseRecordsController();
        $router->get('/enterprise/companies/{tenant_id}/approvals', fn() => $tokenMiddleware->strict(), fn() => $records->approvals());
        $router->post('/enterprise/companies/{tenant_id}/approvals', fn() => $tokenMiddleware->strict(), fn() => $records->approvals(true));
        $router->get('/enterprise/companies/{tenant_id}/records/{kind}', fn() => $tokenMiddleware->strict(), fn() => $records->handle());
        $router->post('/enterprise/companies/{tenant_id}/records/{kind}', fn() => $tokenMiddleware->strict(), fn() => $records->handle(true));
        $square = new \App\Controllers\EnterpriseSquareController();
        $router->post('/enterprise/square/production/webhook', fn() => $square->receiveProduction());
        $router->get('/enterprise/companies/{tenant_id}/square-sandbox', fn() => $tokenMiddleware->strict(), fn() => $square->company());
        $router->get('/enterprise/companies/{tenant_id}/square-production', fn() => $tokenMiddleware->strict(), fn() => $square->productionStatus());
        $router->get('/enterprise/companies/{tenant_id}/checkout-sandbox', fn() => $tokenMiddleware->strict(), fn() => $square->checkout());
        $router->post('/enterprise/companies/{tenant_id}/checkout-sandbox/create', fn() => $tokenMiddleware->strict(), fn() => $square->checkout('create'));
        $router->post('/enterprise/companies/{tenant_id}/checkout-sandbox/refresh', fn() => $tokenMiddleware->strict(), fn() => $square->checkout('refresh'));
        $router->post('/enterprise/companies/{tenant_id}/square-sandbox/link', fn() => $tokenMiddleware->strict(), fn() => $square->company(true));
        $router->post('/enterprise/square/sandbox/webhook', fn() => $square->receive());
        $router->get('/enterprise/companies/square-sandbox-status', fn() => $tokenMiddleware->strict(), fn() => $square->status());
        $appCalls=new \App\Controllers\AppAgentCallsController();
        $router->get('/enterprise/ai-calls/owner/{tenant_id}', fn()=>$tokenMiddleware->strict(), fn()=>$appCalls->handle('owner'));
        $router->get('/enterprise/ai-calls/inbox', fn()=>$tokenMiddleware->strict(), fn()=>$appCalls->handle('inbox'));
        $router->get('/enterprise/ai-calls/detail/{call_id}', fn()=>$tokenMiddleware->strict(), fn()=>$appCalls->handle('detail'));
        $router->post('/enterprise/ai-calls/create', fn()=>$tokenMiddleware->strict(), fn()=>$appCalls->handle('create'));
        $router->post('/enterprise/ai-calls/action', fn()=>$tokenMiddleware->strict(), fn()=>$appCalls->handle('action'));
        $router->post('/enterprise/ai-calls/turn', fn()=>$tokenMiddleware->strict(), fn()=>$appCalls->handle('turn'));
        foreach(['voice-start','voice-poll','voice-stop','voice-ready'] as $voiceAction)$router->post('/enterprise/ai-calls/'.$voiceAction, fn()=>$tokenMiddleware->strict(), fn()=>$appCalls->handle($voiceAction));
        $enterprise = new \App\Controllers\EnterpriseController();
        $router->post('/enterprise/companies/{tenant_id}/support-inbox/reply', fn() => $tokenMiddleware->strict(), fn() => $enterprise->supportTicket());
        $router->post('/enterprise/companies/{tenant_id}/support-inbox/resolve', fn() => $tokenMiddleware->strict(), fn() => $enterprise->supportTicket(true));
        $router->get('/enterprise/companies/{tenant_id}/support-join', fn() => $tokenMiddleware->strict(), fn() => $enterprise->supportJoin());
        $router->post('/enterprise/companies/{tenant_id}/support-join', fn() => $tokenMiddleware->strict(), fn() => $enterprise->supportJoin(true));
        $router->get('/enterprise/companies/{tenant_id}/support-inbox', fn() => $tokenMiddleware->strict(), fn() => $enterprise->supportInbox());
        $router->post('/enterprise/companies/{tenant_id}/support-inbox/review', fn() => $tokenMiddleware->strict(), fn() => $enterprise->supportInbox(true));
        $router->get('/enterprise/companies/{tenant_id}/profile', fn() => $tokenMiddleware->strict(), fn() => $enterprise->profile());
        $router->post('/enterprise/companies/{tenant_id}/profile', fn() => $tokenMiddleware->strict(), fn() => $enterprise->profile(true));
        $router->get('/enterprise/companies/{tenant_id}/tasks', fn() => $tokenMiddleware->strict(), fn() => $enterprise->tasks());
        $router->post('/enterprise/companies/{tenant_id}/tasks', fn() => $tokenMiddleware->strict(), fn() => $enterprise->tasks('create'));
        $router->post('/enterprise/companies/{tenant_id}/tasks/generate', fn() => $tokenMiddleware->strict(), fn() => $enterprise->tasks('generate'));
        $router->post('/enterprise/companies/{tenant_id}/tasks/save', fn() => $tokenMiddleware->strict(), fn() => $enterprise->tasks('save'));
        $router->get('/enterprise/companies/{tenant_id}/agent-preview', fn() => $tokenMiddleware->strict(), fn() => $enterprise->preview());
        $router->post('/enterprise/companies/{tenant_id}/agent-preview', fn() => $tokenMiddleware->strict(), fn() => $enterprise->preview(true));
        $router->post('/enterprise/companies/plan-price', fn() => $tokenMiddleware->strict(), fn() => $enterprise->price());
        $router->get('/enterprise/companies', fn() => $tokenMiddleware->strict(), fn() => $enterprise->list());
        $router->post('/enterprise/companies', fn() => $tokenMiddleware->strict(), fn() => $enterprise->create());
        $router->get('/enterprise/companies/{tenant_id}', fn() => $tokenMiddleware->strict(), fn() => $enterprise->dashboard());
        $router->post('/enterprise/companies/{tenant_id}/members', fn() => $tokenMiddleware->strict(), fn() => $enterprise->member());
        $router->post('/enterprise/companies/{tenant_id}/agents', fn() => $tokenMiddleware->strict(), fn() => $enterprise->agent());
        $router->post('/enterprise/companies/{tenant_id}/memory/read', fn() => $tokenMiddleware->strict(), fn() => $enterprise->memoryRead());
        $router->post('/enterprise/companies/{tenant_id}/memory/write', fn() => $tokenMiddleware->strict(), fn() => $enterprise->memoryWrite());
        $support = new \App\Controllers\SupportPilotController();
        $router->post('/agents/{agent_id}/support/respond', fn() => $support->receive());
        $router->get('/agents/{agent_id}/support/inbox', fn() => $tokenMiddleware->strict(), fn() => $support->inbox());
        $router->get('/agents/{agent_id}/support/settings', fn() => $tokenMiddleware->strict(), fn() => $support->settings());
        $router->patch('/agents/{agent_id}/support/settings', fn() => $tokenMiddleware->strict(), fn() => $support->updateSettings());
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
