<?php
namespace App\Services;

use App\Configs\Database;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PDO;

final class SessionAuth
{
    public static function authenticate(string $token, ?PDO $db = null): object
    {
        if ($token === '' || strlen($token) > 8192) throw new \RuntimeException('Sesión inválida');
        $claims = JWT::decode($token, new Key(JwtSecret::get(), 'HS256'));
        if (!isset($claims->exp, $claims->user_id) || (int) $claims->exp <= time()
            || !ctype_digit((string) $claims->user_id) || (int) $claims->user_id < 1) {
            throw new \RuntimeException('Sesión inválida');
        }
        self::requireActive((int) $claims->user_id, $token, $db);
        return $claims;
    }

    public static function requireActive(int $userId, string $token, ?PDO $db = null): void
    {
        $db ??= Database::getInstance()->getConnection();
        $stmt = $db->prepare('SELECT token FROM user_tokens WHERE user_id = ? AND token = ? AND expires_at > CURRENT_TIMESTAMP');
        $stmt->execute([$userId, $token]);
        $stored = $stmt->fetchColumn();
        if (!is_string($stored) || !hash_equals($stored, $token)) throw new \RuntimeException('Sesión revocada o expirada');
    }
}
