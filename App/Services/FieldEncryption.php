<?php

namespace App\Services;

/**
 * Cifrado AES-256-GCM a nivel de aplicación para datos sensibles (SSN, cuenta bancaria, licencia).
 */
class FieldEncryption
{
    private const PREFIX = 'enc:v1:';

    public static function encrypt(?string $plaintext): ?string
    {
        if ($plaintext === null || $plaintext === '') {
            return $plaintext;
        }

        if (self::isEncrypted($plaintext)) {
            return $plaintext;
        }

        $key = self::key();
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($cipher === false) {
            throw new \RuntimeException('No se pudo cifrar el valor sensible');
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (!self::isEncrypted($value)) {
            return $value;
        }

        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 28) {
            return null;
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        return $plain === false ? null : $plain;
    }

    public static function isEncrypted(?string $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    /**
     * Cifra campos sensibles de form_data de solicitudes de driver.
     */
    public static function encryptDriverFormData(array $formData): array
    {
        if (isset($formData['identity']['licenseNumber'])) {
            $formData['identity']['licenseNumber'] = self::encrypt((string) $formData['identity']['licenseNumber']);
        }

        if (isset($formData['payments']['routingNumber'])) {
            $formData['payments']['routingNumber'] = self::encrypt((string) $formData['payments']['routingNumber']);
        }

        if (isset($formData['payments']['accountNumber'])) {
            $formData['payments']['accountNumber'] = self::encrypt((string) $formData['payments']['accountNumber']);
        }

        if (isset($formData['personal']['ssn'])) {
            $formData['personal']['ssn'] = self::encrypt((string) $formData['personal']['ssn']);
        }

        if (isset($formData['identity']['ssn'])) {
            $formData['identity']['ssn'] = self::encrypt((string) $formData['identity']['ssn']);
        }

        return $formData;
    }

    /**
     * Descifra campos sensibles de form_data (solo para admin al leer detalle).
     */
    public static function decryptDriverFormData(array $formData): array
    {
        if (isset($formData['identity']['licenseNumber'])) {
            $formData['identity']['licenseNumber'] = self::decrypt((string) $formData['identity']['licenseNumber']);
        }

        if (isset($formData['payments']['routingNumber'])) {
            $formData['payments']['routingNumber'] = self::decrypt((string) $formData['payments']['routingNumber']);
        }

        if (isset($formData['payments']['accountNumber'])) {
            $formData['payments']['accountNumber'] = self::decrypt((string) $formData['payments']['accountNumber']);
        }

        if (isset($formData['personal']['ssn'])) {
            $formData['personal']['ssn'] = self::decrypt((string) $formData['personal']['ssn']);
        }

        if (isset($formData['identity']['ssn'])) {
            $formData['identity']['ssn'] = self::decrypt((string) $formData['identity']['ssn']);
        }

        return $formData;
    }

    private static function key(): string
    {
        $raw = $_ENV['APP_ENCRYPTION_KEY'] ?? getenv('APP_ENCRYPTION_KEY') ?: '';

        if ($raw === '') {
            // Fallback: derivar de JWT_SECRET para no romper deploys existentes
            $raw = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: '';
        }

        if ($raw === '' || $raw === 'TU_SECRET_KEY') {
            throw new \RuntimeException('APP_ENCRYPTION_KEY / JWT_SECRET no configurados para cifrado de datos sensibles');
        }

        return hash('sha256', $raw, true);
    }
}
