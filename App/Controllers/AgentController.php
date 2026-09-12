<?php

namespace App\Controllers;

use App\Models\AgentModel;
use EasyProjects\SimpleRouter\Router;
use Exception;

class AgentController
{
    public function __construct(
        private ?AgentModel $agentModel = new AgentModel()
    ) {
    }

    public function listMyAgents()
    {
        try {
            $user = Router::$request->user;
            $userId = $user->id ?? null;
            if (!$userId) {
                return Router::$response->status(401)->send([
                    "success" => false,
                    "message" => "Usuario no autenticado"
                ]);
            }

            return Router::$response->status(200)->send([
                "success" => true,
                "agents" => $this->agentModel->getByUser($userId)
            ]);
        } catch (Exception $e) {
            error_log("Error en listMyAgents: " . $e->getMessage());
            return Router::$response->status(500)->send([
                "success" => false,
                "message" => "Error interno"
            ]);
        }
    }

    public function createAgent()
    {
        try {
            $user = Router::$request->user;
            $userId = $user->id ?? null;
            if (!$userId) {
                return Router::$response->status(401)->send([
                    "success" => false,
                    "message" => "Usuario no autenticado"
                ]);
            }

            $body = Router::$request->body;
            $name = trim((string)($body->name ?? ''));
            $webhookUrl = trim((string)($body->webhook_url ?? ''));

            if ($name === '' || $webhookUrl === '') {
                return Router::$response->status(400)->send([
                    "success" => false,
                    "message" => "Faltan parámetros: name y webhook_url son obligatorios"
                ]);
            }
            if (!filter_var($webhookUrl, FILTER_VALIDATE_URL) || !str_starts_with($webhookUrl, 'https://')) {
                return Router::$response->status(400)->send([
                    "success" => false,
                    "message" => "La URL del webhook debe ser una URL https válida"
                ]);
            }

            $agentId = $this->agentModel->create($userId, $name, $webhookUrl);
            $agent = $this->agentModel->getById($agentId);

            return Router::$response->status(201)->send([
                "success" => true,
                "agent" => [
                    "id" => $agentId,
                    "name" => $name,
                    "webhook_url" => $webhookUrl,
                    "secret" => $agent['secret'] ?? null
                ]
            ]);
        } catch (Exception $e) {
            error_log("Error en createAgent: " . $e->getMessage());
            return Router::$response->status(500)->send([
                "success" => false,
                "message" => "Error interno"
            ]);
        }
    }

    public function updateAgent()
    {
        try {
            $user = Router::$request->user;
            $userId = $user->id ?? null;
            $agentId = (int)(Router::$request->params->agent_id ?? 0);

            if (!$userId || !$agentId) {
                return Router::$response->status(400)->send([
                    "success" => false,
                    "message" => "Faltan parámetros"
                ]);
            }
            if (!$this->agentModel->isOwnedBy($agentId, $userId)) {
                return Router::$response->status(403)->send([
                    "success" => false,
                    "message" => "No tenés permiso sobre este agente"
                ]);
            }

            $body = Router::$request->body;
            $name = trim((string)($body->name ?? ''));
            $webhookUrl = trim((string)($body->webhook_url ?? ''));

            if ($name === '' || $webhookUrl === '') {
                return Router::$response->status(400)->send([
                    "success" => false,
                    "message" => "Faltan parámetros: name y webhook_url son obligatorios"
                ]);
            }
            if (!filter_var($webhookUrl, FILTER_VALIDATE_URL) || !str_starts_with($webhookUrl, 'https://')) {
                return Router::$response->status(400)->send([
                    "success" => false,
                    "message" => "La URL del webhook debe ser una URL https válida"
                ]);
            }

            $this->agentModel->update($agentId, $name, $webhookUrl);

            return Router::$response->status(200)->send([
                "success" => true,
                "message" => "Agente actualizado"
            ]);
        } catch (Exception $e) {
            error_log("Error en updateAgent: " . $e->getMessage());
            return Router::$response->status(500)->send([
                "success" => false,
                "message" => "Error interno"
            ]);
        }
    }

    public function regenerateAgentSecret()
    {
        try {
            $user = Router::$request->user;
            $userId = $user->id ?? null;
            $agentId = (int)(Router::$request->params->agent_id ?? 0);

            if (!$userId || !$agentId) {
                return Router::$response->status(400)->send([
                    "success" => false,
                    "message" => "Faltan parámetros"
                ]);
            }
            if (!$this->agentModel->isOwnedBy($agentId, $userId)) {
                return Router::$response->status(403)->send([
                    "success" => false,
                    "message" => "No tenés permiso sobre este agente"
                ]);
            }

            $newSecret = $this->agentModel->regenerateSecret($agentId);

            return Router::$response->status(200)->send([
                "success" => true,
                "secret" => $newSecret
            ]);
        } catch (Exception $e) {
            error_log("Error en regenerateAgentSecret: " . $e->getMessage());
            return Router::$response->status(500)->send([
                "success" => false,
                "message" => "Error interno"
            ]);
        }
    }

    public function deleteAgent()
    {
        try {
            $user = Router::$request->user;
            $userId = $user->id ?? null;
            $agentId = (int)(Router::$request->params->agent_id ?? 0);

            if (!$userId || !$agentId) {
                return Router::$response->status(400)->send([
                    "success" => false,
                    "message" => "Faltan parámetros"
                ]);
            }
            if (!$this->agentModel->isOwnedBy($agentId, $userId)) {
                return Router::$response->status(403)->send([
                    "success" => false,
                    "message" => "No tenés permiso sobre este agente"
                ]);
            }

            $this->agentModel->delete($agentId);

            return Router::$response->status(200)->send([
                "success" => true,
                "message" => "Agente eliminado"
            ]);
        } catch (Exception $e) {
            error_log("Error en deleteAgent: " . $e->getMessage());
            return Router::$response->status(500)->send([
                "success" => false,
                "message" => "Error interno"
            ]);
        }
    }
}
