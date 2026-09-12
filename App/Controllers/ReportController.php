<?php

namespace App\Controllers;

use App\Models\ReportModel;
use EasyProjects\SimpleRouter\Router;

class ReportController
{
    private ReportModel $model;

    private array $validContexts = ['profile', 'group', 'live', 'chat'];

    public function __construct()
    {
        $this->model = new ReportModel();
    }

    public function submit()
    {
        $userId = Router::$request->user->id ?? null;
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }

        $body = Router::$request->body;
        $reportedUserId = (int) ($body->reported_user_id ?? 0);
        $contextType = $body->context_type ?? 'profile';
        $contextId = isset($body->context_id) ? (int) $body->context_id : null;
        $reason = trim((string) ($body->reason ?? ''));
        $message = trim((string) ($body->message ?? ''));

        if ($reportedUserId <= 0) {
            Router::$response->status(400)->json(["message" => "Falta el usuario a reportar"]);
            return;
        }
        if ($reportedUserId === (int) $userId) {
            Router::$response->status(400)->json(["message" => "No podes reportarte a vos mismo"]);
            return;
        }
        if (!in_array($contextType, $this->validContexts, true)) {
            $contextType = 'profile';
        }
        if ($reason === '') {
            Router::$response->status(400)->json(["message" => "Falta el motivo del reporte"]);
            return;
        }

        $ok = $this->model->create([
            'reporter_id'      => $userId,
            'reported_user_id' => $reportedUserId,
            'context_type'     => $contextType,
            'context_id'       => $contextId,
            'reason'           => $reason,
            'message'          => $message !== '' ? $message : null,
        ]);

        if (!$ok) {
            Router::$response->status(500)->json(["message" => "Error al enviar el reporte"]);
            return;
        }

        Router::$response->status(201)->json(["message" => "Reporte enviado"]);
    }

    public function listPending()
    {
        $user = Router::$request->user ?? null;
        if (!$user || strtoupper($user->rol ?? '') !== 'ADMIN') {
            Router::$response->status(403)->json(["message" => "Solo administradores"]);
            return;
        }
        $reports = $this->model->listPending();
        Router::$response->status(200)->json(["data" => $reports]);
    }

    public function resolve()
    {
        $this->changeStatus('reviewed');
    }

    public function dismiss()
    {
        $this->changeStatus('dismissed');
    }

    private function changeStatus(string $status)
    {
        $user = Router::$request->user ?? null;
        if (!$user || strtoupper($user->rol ?? '') !== 'ADMIN') {
            Router::$response->status(403)->json(["message" => "Solo administradores"]);
            return;
        }
        $reportId = Router::$request->params->id ?? null;
        if (!$reportId) {
            Router::$response->status(400)->json(["message" => "Falta el id del reporte"]);
            return;
        }
        $ok = $this->model->setStatus((int) $reportId, $status, (int) $user->id);
        Router::$response->status($ok ? 200 : 500)->json(["message" => $ok ? "Actualizado" : "Error"]);
    }
}
