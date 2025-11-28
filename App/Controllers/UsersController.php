<?php

namespace App\Controllers;

use App\Models\UsersModel;
use App\Models\DriverModel;
use EasyProjects\SimpleRouter\Router;

class UsersController
{
    public function __construct(
        private ?UsersModel $usuariosModel = new UsersModel(),
        private ?DriverModel $driverModel = new DriverModel(),
    ) {}
   public function getUsers($page = 1)
{
    // ✅ Asegurar que page sea un número
    $page = intval($page) ?: 1;
    
    // Obtener el ID del usuario actual desde el token JWT
    $currentUserId = $this->getCurrentUserId();
    
    $users = $this->usuariosModel->getUsers($page,10, $currentUserId);
    if ($users) {
        // Filtrar para excluir al usuario actual (doble verificación)
        $filteredUsers = array_filter($users, function($user) use ($currentUserId) {
            return $user['id'] != $currentUserId;
        });
        
        // Adaptar los datos para el componente frontend
        $adaptedUsers = array_map(function ($user) {
            return [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'phone' => $user['phone'],
                'role' => $user['rol'] ?? 'user',
                'avatar' => $this->generateDefaultAvatar($user['name']),
                'type' => $user['rol'] ?? 'user',
                'isOnline' => $user['online'] == 1,
                'lastSeen' => $user['created_at'],
                'lastMessage' => null
            ];
        }, $filteredUsers);

        Router::$response->status(200)->send([
            "data" => array_values($adaptedUsers), // Reindexar el array
            "message" => "Has been listed the users"
        ]);
    } else if (is_array($users) && count($users) == 0) {
        Router::$response->status(404)->send([
            "message" => "The users not found"
        ]);
    } else {
        Router::$response->status(500)->send([
            "message" => "An error has occurred"
        ]);
    }
}

// Método para obtener el ID del usuario actual desde el token
private function getCurrentUserId()
{
    // Dependiendo de cómo manejes los tokens JWT en tu aplicación
    $headers = apache_request_headers();
    $token = str_replace('Bearer ', '', $headers['Authorization'] ?? '');
    
    if ($token) {
        // Decodificar el token JWT para obtener el user_id
        $payload = json_decode(base64_decode(explode('.', $token)[1]), true);
        return $payload['user_id'] ?? null;
    }
    
    return null;
}

    private function generateDefaultAvatar(string $name): string
    {
        $initials = strtoupper(substr($name, 0, 2));
        return "https://ui-avatars.com/api/?name=" . urlencode($initials) . "&background=random&color=fff";
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
            Router::$request->body->social,
            Router::$request->body->rol
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
    public function register()
    {
        $name = Router::$request->body->name ?? null;
        $email = Router::$request->body->email ?? null;
        $password = Router::$request->body->password ?? null;
        $phone = Router::$request->body->phone ?? null;
        $rol = Router::$request->body->role ?? null;

        if (!$name || !$email || !$password || !$phone) {
            return Router::$response->status(400)->send([
                "message" => "Missing required fields"
            ]);
        }

        // 🔎 Verificar si el email o el teléfono ya existen
        $existingByEmail = $this->usuariosModel->getUserByEmail($email);
        $existingByPhone = $this->usuariosModel->getUserByPhone($phone);

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

        // 🔹 Guardar usuario
        $userId = $this->usuariosModel->addUser($name, $email, $hashed, $phone, $rol);

        if (!$userId) {
            return Router::$response->status(500)->send([
                "message" => "Error registering user"
            ]);
        }

        try {
            // 🧩 Crear chats con todos los usuarios existentes
            $this->createChatsWithExistingUsers($userId);

            // 🧩 Si el rol es driver, crear registro vacío en la tabla drivers
            if ($rol === 'Driver') {
                $driverModel = new DriverModel();
                $driverModel->createEmptyProfile($userId);
            } else {
                // 🧩 Para usuarios normales, crear perfil vacío en la tabla profiles
                $this->createEmptyUserProfile($userId, $email);
            }

            return Router::$response->status(201)->send([
                "message" => "User registered successfully",
                "user_id" => $userId
            ]);
        } catch (Exception $e) {
            // Si hay error en la creación de chats o perfil, eliminar el usuario creado
            $this->usuariosModel->deleteUser($userId);

            return Router::$response->status(500)->send([
                "message" => "Error creating user profile or chats"
            ]);
        }
    }

    /**
     * Crear chats entre el nuevo usuario y todos los usuarios existentes
     */
    private function createChatsWithExistingUsers(int $newUserId): void
    {
        try {
            // Obtener todos los usuarios existentes (excluyendo el nuevo)
            $existingUsers = $this->usuariosModel->getAllUsersExcept($newUserId);

            if (empty($existingUsers)) {
                return; // No hay usuarios existentes con quienes chatear
            }

            // Crear un chat individual con cada usuario existente
            foreach ($existingUsers as $existingUser) {
                $this->createIndividualChat($newUserId, $existingUser['id']);
            }
        } catch (Exception $e) {
            error_log("Error creating chats for user {$newUserId}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Crear un chat individual entre dos usuarios
     */
    private function createIndividualChat(int $userId1, int $userId2): void
    {
        try {
            // Crear el chat
            $chatId = $this->usuariosModel->createChat([
                'name' => "Chat: {$userId1}-{$userId2}",
                'type' => 'individual',
                'created_by' => $userId1
            ]);

            if ($chatId) {
                // Agregar ambos usuarios al chat
                $this->usuariosModel->addUserToChat($chatId, $userId1);
                $this->usuariosModel->addUserToChat($chatId, $userId2);
            }
        } catch (Exception $e) {
            error_log("Error creating chat between {$userId1} and {$userId2}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Crear perfil vacío para usuario normal
     */
    private function createEmptyUserProfile(int $userId, string $email): void
    {
        try {
            $this->usuariosModel->createUserProfile([
                'user_id' => $userId,
                'email' => $email,
                'bio' => null,
                'website' => null,
                'instagram' => null,
                'facebook' => null,
                'twitter' => null,
                'linkedin' => null,
                'tiktok' => null,
                'avatar' => null
            ]);
        } catch (Exception $e) {
            error_log("Error creating profile for user {$userId}: " . $e->getMessage());
            throw $e;
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

        $user = $this->usuariosModel->getUserStatus((int)$userId);

        if ($user === false) {
            Router::$response->status(500)->send(["message" => "Error obteniendo estado"]);
            return;
        }

        if (empty($user)) {
            Router::$response->status(404)->send(["message" => "Usuario no encontrado"]);
            return;
        }

        $online = $user === 1;

        Router::$response->status(200)->send([
            "online" => $online
        ]);
    }
}
