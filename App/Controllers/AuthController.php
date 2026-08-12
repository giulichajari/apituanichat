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

      if (!$email || !$password) {
        Router::$response->json(['error' => 'Email y contraseña requeridos'], 400);
        return;
      }

      $usersModel = new UsersModel();
      $user = $usersModel->verifyCredentials($email, $password);

      if (!$user) {
        Router::$response->json(['error' => 'Credenciales inválidas'], 401);
        return;
      }

      // 🔹 Generar OTP
      $otp = rand(100000, 999999);

      $db = \App\Configs\Database::getInstance()->getConnection();

      // 🔹 Actualizar usuario con OTP y estado online
      $stmt = $db->prepare("
            UPDATE users  
            SET otp = :otp, otp_created_at = NOW(), last_seen = NOW(), online = 1
            WHERE id = :id
        ");
      $stmt->bindValue(':otp', $otp);
      $stmt->bindValue(':id', $user['id']);
      $stmt->execute();

      // 🔹 Generar JWT (secreto solo desde .env)
      $secretKey = JwtSecret::get();
      $payload = [
        'user_id' => $user['id'],
        'email' => $user['email'],
        'iat' => time(),
        'exp' => time() + 3600 // expira en 1 hora
      ];
      $jwt = JWT::encode($payload, $secretKey, 'HS256');

      $refreshToken = bin2hex(random_bytes(32));
      $refreshExpiresAt = date('Y-m-d H:i:s', time() + (86400 * 30));

      // 🔹 Guardar el token en DB (corregido)
      $expiresAt = date('Y-m-d H:i:s', time() + 3600);
      $createdAt = date('Y-m-d H:i:s');

      // Opción 1: UPDATE si el token ya existe para este usuario
      $stmt = $db->prepare("
            UPDATE user_tokens 
            SET token = :token, refresh_token = :refresh_token, created_at = :created_at, expires_at = :expires_at, refresh_expires_at = :refresh_expires_at
            WHERE user_id = :user_id
        ");
      $stmt->bindValue(':token', $jwt);
      $stmt->bindValue(':created_at', $createdAt);
      $stmt->bindValue(':expires_at', $expiresAt);
      $stmt->bindValue(':user_id', $user['id']);
      $stmt->bindValue(':refresh_token', $refreshToken);
      $stmt->bindValue(':refresh_expires_at', $refreshExpiresAt);
      $stmt->execute();

      // Si no se actualizó ninguna fila, INSERT nuevo token
      if ($stmt->rowCount() === 0) {
        $stmt = $db->prepare("
                INSERT INTO user_tokens (user_id, token, refresh_token, created_at, expires_at, refresh_expires_at)
                VALUES (:user_id, :token, :refresh_token, :created_at, :expires_at, :refresh_expires_at)
            ");
        $stmt->bindValue(':user_id', $user['id']);
        $stmt->bindValue(':token', $jwt);
        $stmt->bindValue(':created_at', $createdAt);
        $stmt->bindValue(':expires_at', $expiresAt);
        $stmt->bindValue(':refresh_token', $refreshToken);
        $stmt->bindValue(':refresh_expires_at', $refreshExpiresAt);
        $stmt->execute();
      }

      // 🔹 Devolver respuesta al frontend
      Router::$response->json([
        'message' => 'Login exitoso',
        'token' => $jwt,
        'refresh_token' => $refreshToken,
        'otp' => $otp,
        'user_id' => $user['id'],
        'rol' => $user['rol'],
        'email' => $user['email'],
        'name' => $user['name']
      ], 200);
    } catch (Exception $e) {
      error_log("Login SQL ERROR: " . $e->getMessage(), 3, "/var/www/apituanichat/php-error.log");
      Router::$response->json(['error' => 'Error en base de datos'], 500);
      return;
    }
  }

  public static function verifyOtp()
  {
    $body = Router::$request->body;
    $email = $body->email ?? null;
    $otp   = $body->otp ?? null;

    if (!$email || !$otp) {
      Router::$response->json(['error' => 'Email y OTP requeridos'], 400);
      return;
    }

    $db = \App\Configs\Database::getInstance()->getConnection();

    // Buscamos usuario
    $stmt = $db->prepare("SELECT id FROM users WHERE email = :email");
    $stmt->bindValue(':email', $email);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
      Router::$response->json(['error' => 'Usuario no encontrado'], 404);
      return;
    }

    // Verificamos OTP en users
    $stmt = $db->prepare("
        SELECT * FROM users
        WHERE id = :id AND otp = :otp AND otp_created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    ");
    $stmt->bindValue(':id', $user['id']);
    $stmt->bindValue(':otp', $otp);
    $stmt->execute();
    $validOtp = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$validOtp) {
      Router::$response->json(['error' => 'OTP inválido o expirado'], 401);
      return;
    }

    // ✅ Generamos JWT (mismo claim user_id que login/middleware)
    $payload = [
      'user_id' => $user['id'],
      'email' => $email,
      'iat' => time(),
      'exp' => time() + 3600 // 1 hora
    ];
    $jwt = JWT::encode($payload, JwtSecret::get(), 'HS256');

    $refreshToken = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + 3600);
    $refreshExpiresAt = date('Y-m-d H:i:s', time() + (86400 * 30));
    $createdAt = date('Y-m-d H:i:s');

    $stmt = $db->prepare("
        UPDATE user_tokens
        SET token = :token, refresh_token = :refresh_token,
            created_at = :created_at, expires_at = :expires_at,
            refresh_expires_at = :refresh_expires_at
        WHERE user_id = :user_id
    ");
    $stmt->bindValue(':token', $jwt);
    $stmt->bindValue(':refresh_token', $refreshToken);
    $stmt->bindValue(':created_at', $createdAt);
    $stmt->bindValue(':expires_at', $expiresAt);
    $stmt->bindValue(':refresh_expires_at', $refreshExpiresAt);
    $stmt->bindValue(':user_id', $user['id']);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        $stmt = $db->prepare("
            INSERT INTO user_tokens (user_id, token, refresh_token, created_at, expires_at, refresh_expires_at)
            VALUES (:user_id, :token, :refresh_token, :created_at, :expires_at, :refresh_expires_at)
        ");
        $stmt->bindValue(':user_id', $user['id']);
        $stmt->bindValue(':token', $jwt);
        $stmt->bindValue(':refresh_token', $refreshToken);
        $stmt->bindValue(':created_at', $createdAt);
        $stmt->bindValue(':expires_at', $expiresAt);
        $stmt->bindValue(':refresh_expires_at', $refreshExpiresAt);
        $stmt->execute();
    }

    // Limpiamos OTP usado
    $stmt = $db->prepare("UPDATE users SET otp = NULL, otp_created_at = NULL WHERE id = :id");
    $stmt->bindValue(':id', $user['id']);
    $stmt->execute();

    Router::$response->json(['token' => $jwt, 'refresh_token' => $refreshToken], 200);
  }

  public static function refreshToken()
  {
    try {
      $body = Router::$request->body;
      $refreshToken = $body->refresh_token ?? null;

      if (!$refreshToken) {
        Router::$response->json(['error' => 'refresh_token requerido'], 400);
        return;
      }

      $db = \App\Configs\Database::getInstance()->getConnection();

      $stmt = $db->prepare("
          SELECT user_id, refresh_expires_at FROM user_tokens
          WHERE refresh_token = :refresh_token
      ");
      $stmt->bindValue(':refresh_token', $refreshToken);
      $stmt->execute();
      $row = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$row) {
        Router::$response->json(['error' => 'refresh_token inválido'], 401);
        return;
      }

      if (strtotime($row['refresh_expires_at']) < time()) {
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
        'iat' => time(),
        'exp' => time() + 3600
      ];
      $jwt = JWT::encode($payload, JwtSecret::get(), 'HS256');
      $expiresAt = date('Y-m-d H:i:s', time() + 3600);

      $stmt = $db->prepare("
          UPDATE user_tokens SET token = :token, expires_at = :expires_at
          WHERE user_id = :user_id
      ");
      $stmt->bindValue(':token', $jwt);
      $stmt->bindValue(':expires_at', $expiresAt);
      $stmt->bindValue(':user_id', $user['id']);
      $stmt->execute();

      Router::$response->json(['token' => $jwt], 200);

    } catch (Exception $e) {
      error_log("Refresh token ERROR: " . $e->getMessage(), 3, "/var/www/apituanichat/php-error.log");
      Router::$response->json(['error' => 'Error renovando sesión'], 500);
    }
  }
  public static function logout()
  {
    try {
      $headers = getallheaders();
      $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

      if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        Router::$response->json(['error' => 'Token no proporcionado'], 401);
        return;
      }

      $token = $matches[1];
      $decoded = JWT::decode($token, new Key(JwtSecret::get(), 'HS256'));
      $userId = $decoded->user_id;

      if (!$userId) {
        Router::$response->json(['error' => 'user_id requerido'], 400);
        return;
      }

      $db = \App\Configs\Database::getInstance()->getConnection();

      // Marcar offline y actualizar last_seen
      $stmt = $db->prepare("
            UPDATE users 
            SET online = 0, last_seen = NOW() 
            WHERE id = :id
        ");
      $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
      $stmt->execute();

      // Opcional: invalidar token guardado
      $stmt = $db->prepare("DELETE FROM user_tokens WHERE id = :id");
      $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
      $stmt->execute();

      Router::$response->json(['message' => 'Logout exitoso'], 200);
    } catch (Exception $e) {
      error_log("Logout SQL ERROR: " . $e->getMessage(), 3, "/var/www/apituanichat/php-error.log");
      Router::$response->json(['error' => 'Error en base de datos'], 500);
    }
  }
}
