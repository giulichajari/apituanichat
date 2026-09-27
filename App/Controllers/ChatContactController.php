<?php
namespace App\Controllers;

use App\Configs\Database;
use EasyProjects\SimpleRouter\Router;
use PDO;

final class ChatContactController
{
    public function show(): void
    {
        if (empty(Router::$request->user->id)) {
            Router::$response->status(401)->send(['message'=>'Sesión requerida']); return;
        }
        $id=Router::$request->params->idUser ?? null;
        if (!is_scalar($id) || !preg_match('/^[1-9][0-9]{0,9}$/D',(string)$id) || (float)$id>4294967295) {
            Router::$response->status(400)->send(['message'=>'Enlace inválido']); return;
        }
        try {
            $db=Database::getInstance()->getConnection();
            $stmt=$db->prepare('SELECT id, name FROM users WHERE id = ?');
            $stmt->execute([(int)$id]);
            $contact=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!$contact) { Router::$response->status(404)->send(['message'=>'Perfil no disponible']); return; }
            // Only public display fields; never expose email, phone, tokens or messages.
            Router::$response->status(200)->send(['data'=>['id'=>(int)$contact['id'],'name'=>(string)$contact['name']]]);
        } catch (\Throwable $e) {
            error_log('Chat contact lookup failed');
            Router::$response->status(503)->send(['message'=>'No se pudo abrir el perfil']);
        }
    }
}
