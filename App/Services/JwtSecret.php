<?php

namespace App\Services;

class JwtSecret
{
    public static function get(): string
    {
        $secret = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: '';

        if ($secret === '' || $secret === 'TU_SECRET_KEY') {
            throw new \RuntimeException('JWT_SECRET no configurado. Defínelo en el archivo .env con un valor aleatorio seguro.');
        }

        return $secret;
    }
}
