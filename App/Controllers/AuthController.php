<?php

namespace App\Controllers;

use Firebase\JWT\JWT;
use EasyProjects\SimpleRouter\Router;
use PDO;
use App\Models\UsersModel;
use App\Services\JwtSecret;
use Exception;
use Firebase\JWT\Key; // ✅ Importar la clase Key

class AuthController
{


  public static function login()
  {
    try {
      $body = Router::$request->body;
      $email = $body->email ?? null;
      $password = $body->password ?? null;

      if (!is_string($email) || !is_string($password) || trim($email) === '' || $password === '' || strlen($email) > 254 || strlen($password) > 4096) {
        Router::$response->json(['error' => 'Email y contraseña requeridos'], 400);
        return;
      }

      try {
        $wait = \App\Services\LoginThrottle::reserve($email, $_SERVER['REMOTE_ADDR'] ?? '');
      } catch (\Throwable $e) {
        error_log('Login limiter unavailable');
        Router::$response->json(['error'=>'Acceso temporalmente no disponible; inténtalo más tarde'], 503);
        return;
      }
      if ($wait > 0) {
        if (!headers_sent()) header('Retry-After: '.$wait);
        Router::$response->json(['error'=>'Demasiados intentos; espera antes de volver a entrar', 'retry_after'=>$wait], 429);
        return;
      }
      $usersModel = new UsersModel();
      $user = $usersModel->verifyCredentials($email, $password);

      if (!$user) {
        Router::$response->json(['error' => 'Credenciales inválidas'], 401);
        return;
      }

      // Preserve password recovery tokens.
      // Login with password does not create or expose recovery codes.

      $db = \App\Configs\Database::getInstance()->getConnection();

      // Update presence only.
      $stmt = $db->prepare("
            UPDATE users  
            SET last_seen = NOW(), online = 1
            WHERE id = :id
        ");
      $stmt->bindValue(':id', $user['id']);
      $stmt->execute();

      // 🔹 Generar JWT (secreto solo desde .env)
      $secretKey = JwtSecret::get();
      $payload = [
        'user_id' => $user['id'],
        'email' => $user['email'],
        'jti' => bin2hex(random_bytes(16)),
        'iat' => time(),
        'exp' => time() + 3600 // expira en 1 hora
      ];
      $jwt = JWT::encode($payload, $secretKey, 'HS256');

      $refreshToken = bin2hex(random_bytes(32));
      $refreshExpiresAt = date('Y-m-d H:i:s', time() + (86400 * 30));

      // 🔹 Guardar el token en DB (corregido)
      $expiresAt = date('Y-m-d H:i:s', time() + 3600);
      $createdAt = date('Y-m-d H:i:s');

      // A separate session for each successful login; never overwrite other devices.
      $saved = \App\Services\AccountSecurity::storeSession($db, (int)$user['id'], $user['pass'], [
        ':user_id'=>$user['id'], ':token'=>$jwt, ':refresh_token'=>$refreshToken,
        ':created_at'=>$createdAt, ':expires_at'=>$expiresAt, ':refresh_expires_at'=>$refreshExpiresAt
      ]);
      if (!$saved) { Router::$response->json(['error'=>'Las credenciales cambiaron; inicia sesión de nuevo'],401); return; }

      // Notificación de nuevo inicio de sesión (no debe interrumpir el login si el correo falla)
      try {
        if (!empty($user['email'])) {
          \App\Services\MailService::send(
            $user['email'],
            'Nuevo inicio de sesión en TuaniChat',
            'Se inició sesión en tu cuenta de TuaniChat el ' . date('d/m/Y H:i') . '. Si no fuiste vos, cambiá tu contraseña de inmediato.'
          );
        }
      } catch (\Throwable $e) {
        error_log('AuthController::login notifyLogin error: ' . $e->getMessage());
      }

      // 🔹 Devolver respuesta al frontend
      Router::$response->json([
        'message' => 'Login exitoso',
        'token' => $jwt,
        'refresh_token' => $refreshToken,
        'user_id' => $user['id'],
        'rol' => $user['rol'],
        'email' => $user['email'],
        'name' => $user['name']
      ], 200);
    } catch (Exception $e) {
      error_log('Login processing failed');
      Router::$response->json(['error' => 'Error en base de datos'], 500);
      return;
    }
  }

  public static function verifyOtp()
  {
    // Legacy endpoint shared password-reset tokens and bypassed password authentication.
    Router::$response->json(['error'=>'Verificación antigua retirada. Inicia sesión con tu contraseña.'], 410);
  }

  public static function refreshToken()
  {
    try {
      $body = Router::$request->body;
      $refreshToken = $body->refresh_token ?? null;

      if (!is_string($refreshToken) || !preg_match('/^[a-f0-9]{64}$/D', $refreshToken)) {
        Router::$response->json(['error' => 'refresh_token requerido'], 400);
        return;
      }

      $db = \App\Configs\Database::getInstance()->getConnection();

      $stmt = $db->prepare("
          SELECT user_id, token, refresh_expires_at FROM user_tokens
          WHERE refresh_token = :refresh_token
      ");
      $stmt->bindValue(':refresh_token', $refreshToken);
      $stmt->execute();
      $row = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$row || $stmt->fetch(PDO::FETCH_ASSOC)) {
        Router::$response->json(['error' => 'refresh_token inválido'], 401);
        return;
      }

      if (strtotime($row['refresh_expires_at']) <= time()) {
        Router::$response->json(['error' => 'refresh_token expirado, inicia sesión de nuevo'], 401);
        return;
      }

      $stmt = $db->prepare("SELECT id, email FROM users WHERE id = :id");
      $stmt->bindValue(':id', $row['user_id']);
      $stmt->execute();
      $user = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$user) {
        Router::$response->json(['error' => 'Usuario no encontrado'], 404);
        return;
      }

      $payload = [
        'user_id' => $user['id'],
        'email' => $user['email'],
        'jti' => bin2hex(random_bytes(16)),
        'iat' => time(),
        'exp' => time() + 3600
      ];
      $jwt = JWT::encode($payload, JwtSecret::get(), 'HS256');
      $expiresAt = date('Y-m-d H:i:s', time() + 3600);

      $stmt = $db->prepare("
          UPDATE user_tokens SET token = :token, expires_at = :expires_at
          WHERE user_id = :user_id AND refresh_token = :refresh_token AND token = :previous_token AND refresh_expires_at > CURRENT_TIMESTAMP
      ");
      $stmt->bindValue(':token', $jwt);
      $stmt->bindValue(':expires_at', $expiresAt);
      $stmt->bindValue(':user_id', $user['id']);
      $stmt->bindValue(':refresh_token', $refreshToken);
      $stmt->bindValue(':previous_token', $row['token']);
      $stmt->execute();
      if ($stmt->rowCount() !== 1) {
        // Concurrent refresh/logout: do not return a token that was never stored.
        Router::$response->json(['error'=>'La sesión cambió; vuelve a intentar la renovación'], 409);
        return;
      }

      Router::$response->json(['token' => $jwt], 200);

    } catch (Exception $e) {
      error_log('Refresh processing failed');
      Router::$response->json(['error' => 'Error renovando sesión'], 500);
    }
  }
  public static function logout()
  {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $header = $headers['Authorization'] ?? $headers['authorization']
      ?? Router::$request->headers->Authorization ?? Router::$request->headers->authorization ?? '';
    if (!is_string($header) || !preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches)) {
      Router::$response->json(['error' => 'Token no proporcionado'], 401);
      return;
    }
    $token = $matches[1];
    try {
      $decoded = JWT::decode($token, new Key(JwtSecret::get(), 'HS256'));
      $userId = filter_var($decoded->user_id ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
      if (!$userId || !isset($decoded->exp) || !is_numeric($decoded->exp) || $decoded->exp <= time()) {
        Router::$response->json(['error' => 'Token inválido o expirado'], 401);
        return;
      }
    } catch (\UnexpectedValueException | \DomainException | \InvalidArgumentException $e) {
      Router::$response->json(['error' => 'Token inválido o expirado'], 401);
      return;
    }
    $db = null;
    try {
      $db = \App\Configs\Database::getInstance()->getConnection();
      $db->beginTransaction();
      // Revocar exclusivamente la sesión presentada, nunca otra fila con el mismo id.
      $stmt = $db->prepare('DELETE FROM user_tokens WHERE user_id = :user_id AND token = :token');
      $stmt->execute([':user_id' => $userId, ':token' => $token]);
      if ($stmt->rowCount() > 0) {
        $stmt = $db->prepare('UPDATE users SET online = 0, last_seen = CURRENT_TIMESTAMP WHERE id = :id AND NOT EXISTS (SELECT 1 FROM user_tokens WHERE user_id = :uid AND expires_at > CURRENT_TIMESTAMP)');
        $stmt->execute([':id' => $userId, ':uid' => $userId]);
      }
      $db->commit();
      Router::$response->json(['message' => 'Logout exitoso'], 200);
    } catch (\Throwable $e) {
      if ($db && $db->inTransaction()) {
        $db->rollBack();
      }
      error_log('Logout failed: ' . get_class($e));
      Router::$response->json(['error' => 'No se pudo cerrar la sesión'], 500);
    }
  }
}
