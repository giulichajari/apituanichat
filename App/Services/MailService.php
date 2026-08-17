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

        if ($smtpHost && class_exists(PHPMailer::class)) {
            try {
                $mail = new PHPMailer(true);
                $mail->CharSet = 'UTF-8';
                $mail->isSMTP();
                $mail->Host = $smtpHost;
                $mail->SMTPAuth = true;
                $mail->Username = self::env('SMTP_USER', '');
                $mail->Password = self::env('SMTP_PASS', '');
                $mail->SMTPSecure = self::env('SMTP_SECURE', 'tls');
                $mail->Port = (int) self::env('SMTP_PORT', '587');
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
            }
        }

        $headers = "From: {$from}\r\n";
        $headers .= "Reply-To: {$replyTo}\r\n";
        $headers .= $isHtml
            ? "Content-Type: text/html; charset=UTF-8\r\n"
            : "Content-Type: text/plain; charset=UTF-8\r\n";
        $sent = @mail($to, $subject, $body, $headers);
        self::log('mail() RESULT', $sent ? 'OK' : 'FAIL');
        return (bool) $sent;
    }

    private static function env(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return (string) $value;
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
