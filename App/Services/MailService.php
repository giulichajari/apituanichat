<?php

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;

class MailService
{
    public static function send(
        string $to,
        string $subject,
        string $body,
        string $fromName = 'Tuanichat',
        bool $isHtml = false
    ): bool {
        $from = self::env('MAIL_FROM', 'noreply@tuanichat.com');
        $replyTo = self::env('MAIL_REPLY', $from);
        $smtpHost = self::env('SMTP_HOST');

        self::log('sendEmail', [
            'to' => $to,
            'smtp' => !empty($smtpHost),
            'phpmailer' => class_exists(PHPMailer::class),
        ]);

        if (!$smtpHost) {
            self::log('SMTP_HOST vacío', 'no se envía');
            return false;
        }

        if (!class_exists(PHPMailer::class)) {
            self::log('PHPMailer not found', 'ejecutá composer install en el servidor');
            return false;
        }

        $username = self::env('SMTP_USER', '');
        $password = self::env('SMTP_PASS', '');
        $secure = strtolower((string) self::env('SMTP_SECURE', 'tls'));
        $port = (int) self::env('SMTP_PORT', $secure === 'ssl' ? '465' : '587');

        self::log('SMTP config', [
            'host' => $smtpHost,
            'port' => $port,
            'secure' => $secure,
            'user' => $username,
            'pass_len' => strlen((string) $password),
        ]);

        try {
            $mail = new PHPMailer(true);
            $mail->CharSet = 'UTF-8';
            $mail->isSMTP();
            $mail->Host = $smtpHost;
            $mail->SMTPAuth = true;
            $mail->AuthType = 'LOGIN';
            $mail->Username = $username;
            $mail->Password = $password;
            $mail->SMTPSecure = $secure === 'ssl'
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $port;
            $mail->Timeout = 20;
            $mail->setFrom($from, $fromName);
            $mail->addReplyTo($replyTo);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->isHTML($isHtml);
            $mail->Body = $body;
            if ($isHtml) {
                $mail->AltBody = trim(html_entity_decode(strip_tags($body), ENT_QUOTES, 'UTF-8'));
            }
            $mail->send();
            self::log('PHPMailer RESULT', 'OK');
            return true;
        } catch (\Throwable $e) {
            self::log('PHPMailer ERROR', $e->getMessage());
            return false;
        }
    }

    private static function env(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        $value = trim((string) $value);
        if (
            strlen($value) >= 2
            && (
                ($value[0] === '"' && str_ends_with($value, '"'))
                || ($value[0] === "'" && str_ends_with($value, "'"))
            )
        ) {
            $value = substr($value, 1, -1);
        }
        return $value === '' ? $default : $value;
    }

    public static function logForgot(string $event, string $email, ?int $userId = null): void
    {
        self::log('forgotPassword ' . $event, [
            'email' => $email,
            'user_id' => $userId,
        ]);
    }

    private static function log(string $message, mixed $data = null): void
    {
        $logsDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($logsDir)) {
            @mkdir($logsDir, 0755, true);
        }
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
        if ($data !== null) {
            $line .= ' | ' . (is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE));
        }
        @file_put_contents($logsDir . DIRECTORY_SEPARATOR . 'mail.log', $line . PHP_EOL, FILE_APPEND);
        error_log($line);
    }
}
