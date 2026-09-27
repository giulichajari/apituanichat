<?php
namespace App\Controllers;

use App\Configs\Database;
use App\Services\OrderLifecycle;
use EasyProjects\SimpleRouter\Router;
use PDO;

final class CommerceController
{
    public function history(): void
    {
        $userId = (int) (Router::$request->user->id ?? 0);
        if ($userId < 1) { Router::$response->status(401)->json(['message' => 'Inicia sesión']); return; }
        $db = Database::getInstance()->getConnection();
        $queries = [
            'shop' => "SELECT o.id, o.status, o.delivery_status, o.total, o.created_at, s.status AS settlement_status, 'buyer' AS role FROM shop_orders o LEFT JOIN commerce_settlements s ON s.reference = CONCAT('shop_order_', o.id) WHERE o.buyer_id = ? ORDER BY o.id DESC LIMIT 40",
            'food' => "SELECT o.id, o.status, o.delivery_status, o.is_delivery, o.driver_id, o.total, o.created_at, s.status AS settlement_status, 'buyer' AS role FROM food_orders o LEFT JOIN commerce_settlements s ON s.reference = CONCAT('food_order_', o.id) WHERE o.user_id = ? ORDER BY o.id DESC LIMIT 40",
        ];
        $rows = [];
        foreach ($queries as $type => $query) {
            $stmt = $db->prepare($query);
            $stmt->execute([$userId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $rows[] = $row + ['type' => $type];
        }
        usort($rows, fn($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));
        Router::$response->status(200)->json(['data' => $rows]);
    }

    public function transition(): void
    {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) { Router::$response->status(400)->json(['message' => 'Solicitud inválida']); return; }
        $userId = (int) (Router::$request->user->id ?? 0);
        if ($userId < 1) { Router::$response->status(401)->json(['message' => 'Inicia sesión']); return; }
        if (!in_array($body['type'] ?? null, ['food', 'shop'], true) || !in_array($body['action'] ?? null, ['cancel', 'complete'], true)) { Router::$response->status(400)->json(['message' => 'Acción no disponible']); return; }
        try {
            $result = (new OrderLifecycle())->transition((string) ($body['type'] ?? ''), (int) ($body['id'] ?? 0), (string) ($body['action'] ?? ''), $userId);
            Router::$response->status(200)->json($result);
        } catch (\DomainException | \InvalidArgumentException $e) {
            Router::$response->status(409)->json(['message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            error_log('Commerce transition failed: ' . $e->getMessage());
            Router::$response->status(503)->json(['message' => 'No se completó la acción. Actualiza el pedido antes de reintentar.']);
        }
    }
}
