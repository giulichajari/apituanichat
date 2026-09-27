<?php

namespace App\Middlewares;

use App\Models\UsersModel;
use App\Services\JwtSecret;
use EasyProjects\SimpleRouter\Router;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;


class TokenMiddleware
{
    private string $secret;
    private $user;

    public function __construct(
        private ?UsersModel $usersModel = new UsersModel()
    ) {
        $this->secret = JwtSecret::get();
    }

    private function unauthorized(string $message): never
    {
        Router::$request->user = null;
        Router::$response->status(401)->send(['message' => $message]);
        // El router ejecuta varios callbacks; return no detiene el controlador siguiente.
        exit;
    }

    public function strict()
    {
        Router::$request->user = null;
        $header = Router::$request->headers->Authorization
            ?? Router::$request->headers->authorization ?? null;
        if (!is_string($header) || !preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches)) {
            $this->unauthorized('Token requerido');
        }
        try {
            $decoded = JWT::decode($matches[1], new Key($this->secret, 'HS256'));
            $id = filter_var($decoded->user_id ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id || !isset($decoded->exp) || !is_numeric($decoded->exp) || $decoded->exp <= time()) {
                $this->unauthorized('Token inválido o expirado');
            }
        } catch (\UnexpectedValueException | \DomainException | \InvalidArgumentException $e) {
            $this->unauthorized('Token inválido o expirado');
        }
        // Un fallo de base de datos no se convierte en un rechazo de credenciales.
        if (!$this->usersModel->hasActiveSession($id, $matches[1])) $this->unauthorized('Sesión revocada o expirada');
        $user = $this->usersModel->getUser($id);
        if (!$user) {
            $this->unauthorized('Usuario inválido');
        }
        Router::$request->user = (object)$user;
        return $user;
    }

    public function optional()
    {
        Router::$request->user = null;
        $authHeader = Router::$request->headers->Authorization ?? Router::$request->headers->authorization ?? null;

        if (is_string($authHeader) && str_starts_with($authHeader, 'Bearer ')) {
            $jwt = substr($authHeader, 7);

            try {
                $decoded = JWT::decode($jwt, new Key($this->secret, 'HS256'));
                $id = filter_var($decoded->user_id ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
                if (!$id || !isset($decoded->exp) || !is_numeric($decoded->exp) || $decoded->exp <= time()
                    || !$this->usersModel->hasActiveSession($id, $jwt)) return;
                $user = $this->usersModel->getUser($id);
                Router::$request->user = $user ? (object)$user : null;
            } catch (\Exception $e) {
                Router::$request->user = null; // sigue siendo opcional
            }
        }
    }
}
