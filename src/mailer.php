<?php
declare(strict_types=1);

namespace App;

final class Mailer
{
    /**
     * Basit gönderim: PHP mail().
     * CyberPanel sunucusunda mail() çalışmazsa, storage/mail.log'a yazar.
     */
    public static function send(string $to, string $subject, string $body): bool
    {
        $to = trim($to);
        if ($to === '') {
            return false;
        }

        $from = (string)(Env::get('MAIL_FROM', 'no-reply@example.com'));
        $fromName = (string)(Env::get('MAIL_FROM_NAME', 'RİBA'));

        // UTF-8 subject
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $headers = [];
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        $headers[] = 'From: ' . self::formatFrom($fromName, $from);

        $ok = false;
        try {
            // @: uyarı bastırma (bazı sunucularda mail() uyarı verebiliyor)
            $ok = @mail($to, $encodedSubject, $body, implode("\r\n", $headers));
        } catch (\Throwable $e) {
            $ok = false;
        }

        if (!$ok) {
            self::logFailure($to, $subject, $body);
        }

        return $ok;
    }

    private static function formatFrom(string $name, string $email): string
    {
        $name = trim($name);
        $email = trim($email);
        if ($name === '') {
            return $email;
        }
        $encName = '=?UTF-8?B?' . base64_encode($name) . '?=';
        return $encName . ' <' . $email . '>';
    }

    private static function logFailure(string $to, string $subject, string $body): void
    {
        $root = dirname(__DIR__);
        $dir = $root . '/storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $path = $dir . '/mail.log';
        $line = '[' . date('c') . '] MAIL_FAIL to=' . $to . ' subject=' . str_replace("\n", ' ', $subject) . "\n";
        $line .= $body . "\n---\n";
        @file_put_contents($path, $line, FILE_APPEND);
    }
}

