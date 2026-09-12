<?php

namespace App\Controllers;

use App\Models\WalletModel;
use EasyProjects\SimpleRouter\Router;

class AdminWalletController
{
    private WalletModel $walletModel;

    public function __construct()
    {
        $this->walletModel = new WalletModel();
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

    public function overview()
    {
        if (!$this->requireAdmin()) return;
        Router::$response->status(200)->json($this->walletModel->getSystemOverview());
    }

    public function listWallets()
    {
        if (!$this->requireAdmin()) return;
        $limit = (int) (Router::$request->query['limit'] ?? 50);
        $offset = (int) (Router::$request->query['offset'] ?? 0);
        $search = (string) (Router::$request->query['search'] ?? '');
        Router::$response->status(200)->json(
            $this->walletModel->getAllWalletsWithUser($limit, $offset, $search)
        );
    }

    public function listTransactions()
    {
        if (!$this->requireAdmin()) return;
        $limit = (int) (Router::$request->query['limit'] ?? 100);
        $offset = (int) (Router::$request->query['offset'] ?? 0);
        $type = Router::$request->query['type'] ?? null;
        Router::$response->status(200)->json(
            $this->walletModel->getAllTransactions($limit, $offset, $type)
        );
    }

    public function setStatus($userId)
    {
        if (!$this->requireAdmin()) return;
        $body = json_decode(file_get_contents('php://input'), true);
        $status = $body['status'] ?? '';

        if (!in_array($status, ['active', 'frozen'], true)) {
            Router::$response->status(400)->json(['message' => "status debe ser 'active' o 'frozen'"]);
            return;
        }

        $ok = $this->walletModel->setWalletStatus((int) $userId, $status);
        if (!$ok) {
            Router::$response->status(400)->json(['message' => 'No se pudo actualizar']);
            return;
        }

        Router::$response->status(200)->json(['message' => "Wallet marcada como $status"]);
    }

    public function adjustBalance($userId)
    {
        if (!$this->requireAdmin()) return;
        $body = json_decode(file_get_contents('php://input'), true);
        $amount = (float) ($body['amount'] ?? 0);
        $reason = (string) ($body['reason'] ?? 'Ajuste manual de admin');

        if ($amount == 0) {
            Router::$response->status(400)->json(['message' => 'El monto no puede ser 0']);
            return;
        }

        $result = $this->walletModel->adjustBalance((int) $userId, $amount, $reason);
        if (!$result['success']) {
            Router::$response->status(400)->json(['message' => $result['message']]);
            return;
        }

        Router::$response->status(200)->json([
            'message' => 'Saldo ajustado',
            'new_balance' => $result['new_balance'],
        ]);
    }

    public function listAlerts()
    {
        if (!$this->requireAdmin()) return;
        $onlyUnresolved = (Router::$request->query['all'] ?? '') !== '1';
        Router::$response->status(200)->json($this->walletModel->getAlerts($onlyUnresolved));
    }

    public function resolveAlert($id)
    {
        if (!$this->requireAdmin()) return;
        $ok = $this->walletModel->resolveAlert((int) $id);
        if (!$ok) {
            Router::$response->status(400)->json(['message' => 'No se pudo resolver la alerta']);
            return;
        }
        Router::$response->status(200)->json(['message' => 'Alerta resuelta']);
    }
}
