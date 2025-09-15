<?php

namespace App\Middlewares;

use App\Models\UsersModel;
use EasyProjects\SimpleRouter\Router;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class TokenMiddleware
{
    private string $secret = "TU_SECRET_KEY"; // Ideal mover a .env
    private $user;

    public function __construct(
        private ?UsersModel $usersModel = new UsersModel()
    ) {}

    public function strict()
    {
        $authHeader = Router::$request->headers->Authorization ?? null;

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            Router::$response->status(401)->send([
                "message" => "Token requerido"
            ]);
        }

        $jwt = substr($authHeader, 7); // quitar "Bearer "

        try {
            // 🔑 Decodificar el JWT
            $decoded = JWT::decode($jwt, new Key($this->secret, 'HS256'));


            $user = $this->usersModel->getUser($decoded->user_id);


            if (!$user) {
                Router::$response->status(401)->send([
                    "message" => "Usuario inválido"
                ]);
            }
            return $user;



        } catch (\Exception $e) {
            Router::$response->status(401)->send([
                "message" => "Token inválido o expirado",
                "error" => $e->getMessage()
            ]);
        }
    }

    public function optional()
    {
        $authHeader = Router::$request->headers->Authorization ?? null;

        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            $jwt = substr($authHeader, 7);

            try {
                $decoded = JWT::decode($jwt, new Key($this->secret, 'HS256'));
                Router::$request->user = $this->usersModel->getUser($decoded->user_id);
            } catch (\Exception $e) {
                Router::$request->user = null; // sigue siendo opcional
            }
        }
    }
}
