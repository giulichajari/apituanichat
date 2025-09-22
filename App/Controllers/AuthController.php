<?php

namespace App\Controllers;

use Firebase\JWT\JWT;
use EasyProjects\SimpleRouter\Router;
use PDO;
use App\Models\UsersModel;
use Exception;

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

      // 🔹 Generar OTP si querés
      $otp = rand(100000, 999999);

      $db = \App\Configs\Database::getInstance()->getConnection();



      $stmt = $db->prepare("
        UPDATE users 
        SET otp = :otp, otp_created_at = NOW(), last_seen = NOW(),online=1
        WHERE id = :id
    ");
      $stmt->bindValue(':otp', $otp);
      $stmt->bindValue(':id', $user['id']);
      $stmt->execute();

      // 🔹 Generar JWT
      $secretKey = "TU_SECRET_KEY"; // debe coincidir con tu TokenMiddleware
      $payload = [
        'user_id' => $user['id'],
        'email' => $user['email'],
        'iat' => time(),
        'exp' => time() + 3600 // expira en 1 hora
      ];
      $jwt = JWT::encode($payload, $secretKey, 'HS256');

      // 🔹 Guardar el token en DB si querés (opcional, para revocarlo)
      $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hora desde ahora
      $createdAt = date('Y-m-d H:i:s');

      $stmt = $db->prepare("
    UPDATE user_tokens 
    SET token = :token, created_at = :created_at, expires_at = :expires_at
    WHERE id = :id
");
      $stmt->bindValue(':token', $jwt);
      $stmt->bindValue(':created_at', $createdAt);
      $stmt->bindValue(':expires_at', $expiresAt);
      $stmt->bindValue(':id', $user['id']);
      $stmt->execute();


      // 🔹 Devolver token al frontend
      Router::$response->json([
        'message' => 'Login exitoso',
        'token' => $jwt,
        'otp' => $otp,
        'user_id' => $user['id']
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

    // ✅ Generamos JWT
    $payload = [
      'sub' => $user['id'],
      'email' => $email,
      'iat' => time(),
      'exp' => time() + 3600 // 1 hora
    ];
    $jwt = JWT::encode($payload, $_ENV['JWT_SECRET'], 'HS256');

    // Limpiamos OTP usado
    $stmt = $db->prepare("UPDATE users SET otp = NULL, otp_created_at = NULL WHERE id = :id");
    $stmt->bindValue(':id', $user['id']);
    $stmt->execute();

    Router::$response->json(['token' => $jwt], 200);
  }
  public static function logout()
  {
    try {
      $body = Router::$request->body;
      $userId = $body->user_id ?? null;

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
