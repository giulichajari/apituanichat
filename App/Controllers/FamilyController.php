<?php

namespace App\Controllers;

use App\Models\FamilyModel;
use EasyProjects\SimpleRouter\Router;

class FamilyController
{
    private FamilyModel $model;

    public function __construct()
    {
        $this->model = new FamilyModel();
    }

    public function createInvite()
    {
        $userId = Router::$request->user->id ?? null;
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }
        $code = $this->model->createInvite((int) $userId);
        if (!$code) {
            Router::$response->status(500)->json(["message" => "Error generando el codigo"]);
            return;
        }
        Router::$response->status(201)->json(["code" => $code]);
    }

    public function joinInvite()
    {
        $userId = Router::$request->user->id ?? null;
        $code = Router::$request->body->code ?? null;
        if (!$userId || !$code) {
            Router::$response->status(400)->json(["message" => "Falta el codigo"]);
            return;
        }
        $result = $this->model->joinWithCode((int) $userId, (string) $code);
        Router::$response->status($result['success'] ? 200 : 400)->json($result);
    }

    public function getChildren()
    {
        $userId = Router::$request->user->id ?? null;
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }
        $children = $this->model->getChildrenForParent((int) $userId);
        Router::$response->status(200)->json(["data" => $children]);
    }

    public function getParent()
    {
        $userId = Router::$request->user->id ?? null;
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }
        $parent = $this->model->getParentForChild((int) $userId);
        Router::$response->status(200)->json(["data" => $parent]);
    }

    public function updateChildSettings()
    {
        $userId = Router::$request->user->id ?? null;
        $linkId = Router::$request->params->id ?? null;
        if (!$userId || !$linkId) {
            Router::$response->status(400)->json(["message" => "Falta informacion"]);
            return;
        }
        $body = Router::$request->body;
        $blockAdult = isset($body->block_adult_content) ? (bool) $body->block_adult_content : null;
        $walletShare = isset($body->wallet_share) ? (bool) $body->wallet_share : null;

        $ok = $this->model->updateChildSettings((int) $userId, (int) $linkId, $blockAdult, $walletShare);
        Router::$response->status($ok ? 200 : 403)->json(["message" => $ok ? "Actualizado" : "No autorizado"]);
    }

    public function unlink()
    {
        $userId = Router::$request->user->id ?? null;
        $linkId = Router::$request->params->id ?? null;
        if (!$userId || !$linkId) {
            Router::$response->status(400)->json(["message" => "Falta informacion"]);
            return;
        }
        $ok = $this->model->unlink((int) $userId, (int) $linkId);
        Router::$response->status($ok ? 200 : 500)->json(["message" => $ok ? "Vinculo eliminado" : "Error"]);
    }
}
