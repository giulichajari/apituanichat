<?php

namespace App\Controllers;

use App\Models\UsersModel;
use App\Models\UsuariosModel;
use EasyProjects\SimpleRouter\Router;

class UsersController
{
    public function __construct(
        private ?UsersModel $usuariosModel = new UsersModel(),
    ) {}

    public function getUsers()
    {
        $users = $this->usuariosModel->getUsers(Router::$request->params->page);

        if ($users) {
            Router::$response->status(200)->send([
                "data" => $this->usuariosModel->getUsers(Router::$request->params->page),
                "message" => "Has been listed the users"
            ]);
        } else if (is_array($users) && count($users) == 0) {
            Router::$response->status(404)->send([
                "message" => "The users not found"
            ]);
        } else {
            Router::$response->status(500)->send([
                "message" => "An error has ocurred"
            ]);
        }
    }

    public function getUser()
    {
        $user = $this->usuariosModel->getUser(Router::$request->params->idUser);

        if ($user) {
            Router::$response->status(200)->send([
                "data" => $user,
                "message" => "Has been listed the users"
            ]);
        } else if (is_array($user) && count($user) == 0) {
            Router::$response->status(404)->send([
                "message" => "The user not found"
            ]);
        } else {
            Router::$response->status(500)->send([
                "message" => "An error has ocurred"
            ]);
        }
    }

    public function addUser()
    {
        if ($this->usuariosModel->addUser(
            Router::$request->body->id,
            Router::$request->body->name,
            Router::$request->body->email,
            Router::$request->body->social
        )) {
            Router::$response->status(201)->send([
                "message" => "The user has been created"
            ]);
        } else {
            Router::$response->status(500)->send([
                "message" => "An error has ocurred"
            ]);
        }
    }

    public function updateUser()
    {
        if ($this->usuariosModel->updateUser(
            Router::$request->params->idUser,
            Router::$request->body->name,
            Router::$request->body->email,
            Router::$request->body->social
        )) {
            Router::$response->status(200)->send([
                "message" => "The user has been updated"
            ]);
        } else {
            Router::$response->status(500)->send([
                "message" => "An error has ocurred"
            ]);
        }
    }

    public function deleteUser()
    {
        if ($this->usuariosModel->deleteUser(Router::$request->params->idUser)) {
            Router::$response->status(200)->send([
                "message" => "The user has been deleted"
            ]);
        } else {
            Router::$response->status(500)->send([
                "message" => "An error has ocurred"
            ]);
        }
    }
    /**
     * Registro de usuario
     */
    public function register()
    {
        $name = Router::$request->body->name ?? null;
        $email = Router::$request->body->email ?? null;
        $password = Router::$request->body->password ?? null;
        $phone = Router::$request->body->phone ?? null;

        if (!$name || !$email || !$password || !$phone) {
            return Router::$response->status(400)->send([
                "message" => "Missing required fields"
            ]);
        }

        // 🔎 Verificar si el email o el teléfono ya existen
        $existingByEmail = $this->usuariosModel->getUserByEmail($email);
        $existingByPhone = $this->usuariosModel->getUserByPhone($phone); // <-- hay que crear este método

        if ($existingByEmail) {
            return Router::$response->status(409)->send([
                "message" => "Email already registered"
            ]);
        }

        if ($existingByPhone) {
            return Router::$response->status(409)->send([
                "message" => "Phone number already registered"
            ]);
        }

        // 🔐 Hashear contraseña antes de guardar
        $hashed = password_hash($password, PASSWORD_BCRYPT);

        $success = $this->usuariosModel->addUser($name, $email, $hashed, $phone);

        if ($success) {
            Router::$response->status(201)->send([
                "message" => "User registered successfully"
            ]);
        } else {
            Router::$response->status(500)->send([
                "message" => "Error registering user"
            ]);
        }
    }


    /**
     * Recuperar contraseña (envío de enlace al correo)
     */
    public function forgotPassword()
    {
        $email = Router::$request->body->email ?? null;

        if (!$email) {
            return Router::$response->status(400)->send([
                "message" => "Email is required"
            ]);
        }

        $user = $this->usuariosModel->getUserByEmail($email);
        if (!$user) {
            return Router::$response->status(404)->send([
                "message" => "User not found"
            ]);
        }

        // 🔑 Generar token temporal de reseteo
        $token = bin2hex(random_bytes(32));
        $this->usuariosModel->storeResetToken($user["id"], $token);

        // Enviar email (placeholder)
        // En un proyecto real usarías PHPMailer o similar
        // mail($email, "Password reset", "Use this token: $token");

        Router::$response->status(200)->send([
            "message" => "Password reset link sent",
            "token_dev" => $token // 👈 solo para pruebas
        ]);
    }

    /**
     * Restablecer contraseña con token
     */
    public function resetPassword()
    {
        $token = Router::$request->body->token ?? null;
        $newPassword = Router::$request->body->password ?? null;

        if (!$token || !$newPassword) {
            return Router::$response->status(400)->send([
                "message" => "Token and new password are required"
            ]);
        }

        $userId = $this->usuariosModel->getUserIdByResetToken($token);
        if (!$userId) {
            return Router::$response->status(400)->send([
                "message" => "Invalid or expired token"
            ]);
        }

        $hashed = password_hash($newPassword, PASSWORD_BCRYPT);
        $updated = $this->usuariosModel->updatePassword($userId, $hashed);

        if ($updated) {
            Router::$response->status(200)->send([
                "message" => "Password updated successfully"
            ]);
        } else {
            Router::$response->status(500)->send([
                "message" => "Error updating password"
            ]);
        }
    }
    public function status()
    {
        $userId = Router::$request->params->idUser;

        $user = $this->usuariosModel->getUserStatus($userId);

        if ($user === false) {
            Router::$response->status(500)->send(["message" => "Error obteniendo estado"]);
            return;
        }

        if (empty($user)) {
            Router::$response->status(404)->send(["message" => "Usuario no encontrado"]);
            return;
        }

        $online = (time() - strtotime($user['last_seen'])) < 120;

        Router::$response->status(200)->send([
            "online" => $online,
            "last_seen" => $user['last_seen']
        ]);
    }
}
