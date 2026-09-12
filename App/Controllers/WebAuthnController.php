<?php

namespace App\Controllers;

use App\Models\WebAuthnModel;
use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\Binary\ByteBuffer;
use EasyProjects\SimpleRouter\Router;

class WebAuthnController
{
    private WebAuthnModel $model;
    private string $rpId;
    private string $rpName = 'TuaniChat';

    public function __construct()
    {
        $this->model = new WebAuthnModel();
        // El RP ID debe ser exactamente el dominio (sin https:// ni puerto).
        $this->rpId = $_ENV['WEBAUTHN_RP_ID'] ?? 'tuanichat.com';
    }

    private function webauthn(): WebAuthn
    {
        // Los datos binarios (challenge, ids) se serializan como base64url en el JSON
        // que recibe el navegador — es lo que espera nuestro frontend para armar el ArrayBuffer.
        ByteBuffer::$useBase64UrlEncoding = true;

        // 'none' = no exigimos certificado de fabricante, solo confirmamos que sea
        // el mismo dispositivo/Face ID/huella que se usó al registrar. Es lo normal
        // para apps web (no hardware corporativo tipo Yubikey).
        return new WebAuthn($this->rpName, $this->rpId, ['none']);
    }

    private function currentUserId(): ?int
    {
        $userId = Router::$request->user->id ?? null;
        return $userId ? (int) $userId : null;
    }

    // Paso 1 de registro: pedir al navegador que muestre el diálogo de Face ID / huella.
    public function registerOptions()
    {
        $userId = $this->currentUserId();
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }

        $user = Router::$request->user;
        $userName = $user->email ?? $user->name ?? ('user' . $userId);
        $userDisplayName = $user->name ?? $userName;

        // El userId que pide la librería es binario; usamos el id numérico como bytes.
        $userIdBinary = str_pad((string) $userId, 8, "\0", STR_PAD_LEFT);

        try {
            $webauthn = $this->webauthn();
            $createArgs = $webauthn->getCreateArgs(
                $userIdBinary,
                $userName,
                $userDisplayName,
                60 * 2,     // timeout: 2 minutos
                false,      // requireResidentKey
                'preferred' // requireUserVerification (Face ID / huella si el dispositivo lo soporta)
            );

            $this->model->saveChallenge($userId, $webauthn->getChallenge(), 'register');

            Router::$response->status(200)->json($createArgs);
        } catch (\Throwable $e) {
            error_log('WebAuthnController::registerOptions error: ' . $e->getMessage());
            Router::$response->status(500)->json(["message" => "No se pudo iniciar el registro"]);
        }
    }

    // Paso 2 de registro: el navegador ya generó la credencial, la verificamos y guardamos.
    public function registerVerify()
    {
        $userId = $this->currentUserId();
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }

        $body = json_decode(file_get_contents('php://input'), true);
        $label = (string) ($body['label'] ?? 'Face ID / Huella');

        try {
            $clientDataJSON = base64_decode($body['clientDataJSON'] ?? '');
            $attestationObject = base64_decode($body['attestationObject'] ?? '');
            $challenge = $this->model->consumeChallenge($userId, 'register');

            if (!$challenge) {
                Router::$response->status(400)->json(["message" => "El registro expiró, intenta de nuevo"]);
                return;
            }

            $webauthn = $this->webauthn();
            $data = $webauthn->processCreate($clientDataJSON, $attestationObject, $challenge, false, true, false);

            $this->model->saveCredential(
                $userId,
                $data->credentialId,
                $data->credentialPublicKey,
                $data->signatureCounter ?? 0,
                $data->aaguid ?? null,
                $label
            );

            Router::$response->status(201)->json(["message" => "Face ID / huella registrada correctamente"]);
        } catch (\Throwable $e) {
            error_log('WebAuthnController::registerVerify error: ' . $e->getMessage());
            Router::$response->status(400)->json(["message" => "No se pudo verificar el registro", "error" => $e->getMessage()]);
        }
    }

    // Paso 1 de verificación (al enviar dinero, por ejemplo).
    public function authOptions()
    {
        $userId = $this->currentUserId();
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }

        $credentials = $this->model->getCredentialsForAuth($userId);
        if (empty($credentials)) {
            Router::$response->status(400)->json(["message" => "No tienes Face ID / huella registrada"]);
            return;
        }

        $ids = array_map(fn($c) => $c['credential_id_bin'], $credentials);

        try {
            $webauthn = $this->webauthn();
            $getArgs = $webauthn->getGetArgs($ids, 60 * 2, true, true, true, true, true, 'preferred');

            $this->model->saveChallenge($userId, $webauthn->getChallenge(), 'auth');

            Router::$response->status(200)->json($getArgs);
        } catch (\Throwable $e) {
            error_log('WebAuthnController::authOptions error: ' . $e->getMessage());
            Router::$response->status(500)->json(["message" => "No se pudo iniciar la verificación"]);
        }
    }

    // Paso 2 de verificación: confirma que sí fue Face ID / huella del dueño de la cuenta.
    // Devuelve true/false (lo usa transfer() antes de mover el dinero).
    public function verifyAssertion(int $userId, array $body): array
    {
        try {
            $clientDataJSON = base64_decode($body['clientDataJSON'] ?? '');
            $authenticatorData = base64_decode($body['authenticatorData'] ?? '');
            $signature = base64_decode($body['signature'] ?? '');
            $rawId = base64_decode($body['rawId'] ?? '');

            $challenge = $this->model->consumeChallenge($userId, 'auth');
            if (!$challenge) {
                return ['ok' => false, 'message' => 'La verificación expiró, intenta de nuevo'];
            }

            $credential = $this->model->findCredentialByRawId($userId, $rawId);
            if (!$credential) {
                return ['ok' => false, 'message' => 'Credencial no reconocida'];
            }

            $webauthn = $this->webauthn();
            $webauthn->processGet(
                $clientDataJSON,
                $authenticatorData,
                $signature,
                base64_decode($credential['public_key']),
                $challenge,
                (int) $credential['sign_count'],
                false,
                true
            );

            $newCount = $webauthn->getSignatureCounter();
            if ($newCount !== null) {
                $this->model->updateSignCount((int) $credential['id'], $newCount);
            }

            return ['ok' => true];
        } catch (\Throwable $e) {
            error_log('WebAuthnController::verifyAssertion error: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'No se pudo verificar Face ID / huella'];
        }
    }

    // Endpoint independiente para probar la verificación desde el frontend, sin ligarlo aún a una transferencia.
    public function verify()
    {
        $userId = $this->currentUserId();
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }
        $body = json_decode(file_get_contents('php://input'), true);
        $result = $this->verifyAssertion($userId, $body);
        Router::$response->status($result['ok'] ? 200 : 400)->json($result);
    }

    public function status()
    {
        $userId = $this->currentUserId();
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }
        Router::$response->status(200)->json([
            "enabled" => $this->model->hasAnyCredential($userId),
            "credentials" => $this->model->getCredentialsByUser($userId),
        ]);
    }

    public function deleteCredential($id)
    {
        $userId = $this->currentUserId();
        if (!$userId) {
            Router::$response->status(401)->json(["message" => "Usuario no autenticado"]);
            return;
        }
        $ok = $this->model->deleteCredential((int) $id, $userId);
        Router::$response->status($ok ? 200 : 400)->json(["message" => $ok ? "Eliminada" : "No se pudo eliminar"]);
    }
}
