<?php

namespace App\Controllers;

use App\Models\CountryRateModel;
use EasyProjects\SimpleRouter\Router;

class CountryRateController
{
    private CountryRateModel $model;

    public function __construct()
    {
        $this->model = new CountryRateModel();
    }

    /** GET /country-rates — público (Remis) */
    public function listRates(): void
    {
        $rates = $this->model->getAllActive();
        Router::$response->status(200)->json([
            'message' => 'OK',
            'data' => $rates,
            'meta' => [
                'class_increment' => 1.42,
                'range_policy' => 'midpoint',
            ],
        ]);
    }

    /** GET /country-rates/by-alpha2/{code} — público */
    public function getByAlpha2(): void
    {
        $code = strtoupper(trim(Router::$request->params->code ?? ''));
        if ($code === '' || strlen($code) !== 2) {
            Router::$response->status(400)->json(['message' => 'Código alpha-2 inválido']);
            return;
        }

        $rate = $this->model->getByAlpha2($code);
        if (!$rate) {
            Router::$response->status(404)->json([
                'message' => 'País no encontrado.',
                'data' => null,
            ]);
            return;
        }

        Router::$response->status(200)->json([
            'message' => 'OK',
            'data' => $rate,
        ]);
    }

    /** PUT /country-rates/{id} — solo ADMIN */
    public function updateRate(): void
    {
        if (!$this->requireAdmin()) {
            return;
        }

        $id = (int) (Router::$request->params->id ?? 0);
        if ($id <= 0) {
            Router::$response->status(400)->json(['message' => 'ID inválido']);
            return;
        }

        $existing = $this->model->getById($id);
        if (!$existing) {
            Router::$response->status(404)->json(['message' => 'Tarifa no encontrada']);
            return;
        }

        $body = Router::$request->body ?? null;
        $payload = [];
        if (is_object($body)) {
            $payload = (array) $body;
        } elseif (is_array($body)) {
            $payload = $body;
        }

        $data = [];

        if (isset($payload['passenger_rate_min'])) {
            $data['passenger_rate_min'] = round((float) $payload['passenger_rate_min'], 4);
        }
        if (isset($payload['passenger_rate_max'])) {
            $data['passenger_rate_max'] = round((float) $payload['passenger_rate_max'], 4);
        }

        if (
            isset($data['passenger_rate_min'], $data['passenger_rate_max']) &&
            $data['passenger_rate_min'] > $data['passenger_rate_max']
        ) {
            Router::$response->status(400)->json(['message' => 'El mínimo no puede ser mayor al máximo']);
            return;
        }

        if (isset($payload['is_active'])) {
            $data['is_active'] = (int) ((bool) $payload['is_active']);
        }

        if (empty($data)) {
            Router::$response->status(400)->json(['message' => 'Sin campos para actualizar']);
            return;
        }

        $ok = $this->model->update($id, $data);
        if (!$ok) {
            Router::$response->status(500)->json(['message' => 'Error al actualizar tarifas']);
            return;
        }

        Router::$response->status(200)->json([
            'message' => 'Tarifas actualizadas',
            'data' => $this->model->getById($id),
        ]);
    }

    private function requireAdmin(): bool
    {
        $user = Router::$request->user ?? null;
        if (!$user || strtoupper($user->rol ?? '') !== 'ADMIN') {
            Router::$response->status(403)->json(['message' => 'Acceso denegado. Se requiere rol ADMIN']);
            return false;
        }
        return true;
    }
}
