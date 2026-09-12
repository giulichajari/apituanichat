<?php

namespace App\Controllers;

use App\Models\ScheduledPaymentModel;
use EasyProjects\SimpleRouter\Router;

class ScheduledPaymentController
{
    private ScheduledPaymentModel $model;

    public function __construct(?ScheduledPaymentModel $model = null)
    {
        $this->model = $model ?? new ScheduledPaymentModel();
    }

    public function create()
    {
        $userId = (int) (Router::$request->user->id ?? 0);
        $body = Router::$request->body;

        $serviceType = trim((string) ($body->service_type ?? ''));
        $descripcion = isset($body->descripcion) ? trim((string) $body->descripcion) : null;
        $amount = (float) ($body->amount ?? 0);
        $executionDate = trim((string) ($body->execution_date ?? ''));

        if (!$userId || $serviceType === '' || $amount <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $executionDate)) {
            Router::$response->status(400)->send(["message" => "Datos invalidos"]);
            return;
        }

        $id = $this->model->create($userId, $serviceType, $descripcion, $amount, $executionDate);
        if (!$id) {
            Router::$response->status(500)->send(["message" => "Error al crear el pago programado"]);
            return;
        }

        Router::$response->status(201)->send([
            "message" => "Pago programado creado correctamente",
            "data" => ["id" => $id]
        ]);
    }

    public function list()
    {
        $userId = (int) (Router::$request->user->id ?? 0);
        if (!$userId) {
            Router::$response->status(400)->send(["message" => "Datos invalidos"]);
            return;
        }

        $pagos = $this->model->getByUser($userId);
        Router::$response->status(200)->send([
            "data" => $pagos,
            "message" => "Pagos programados listados correctamente"
        ]);
    }

    public function cancel()
    {
        $userId = (int) (Router::$request->user->id ?? 0);
        $idPago = (int) (Router::$request->params->idPago ?? 0);

        if (!$userId || !$idPago) {
            Router::$response->status(400)->send(["message" => "Datos invalidos"]);
            return;
        }

        $ok = $this->model->cancel($idPago, $userId);
        if (!$ok) {
            Router::$response->status(404)->send(["message" => "Pago no encontrado o ya procesado"]);
            return;
        }

        Router::$response->status(200)->send(["message" => "Pago programado cancelado"]);
    }
}
