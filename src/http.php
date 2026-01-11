<?php
declare(strict_types=1);

namespace App;

final class Http
{
    public static function text(int $status, string $body): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $body;
    }

    public static function html(int $status, string $html): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
    }

    public static function notFound(): void
    {
        self::text(404, "Bulunamadı.\n");
    }

    public static function badRequest(string $message = "Geçersiz istek.\n"): void
    {
        self::text(400, $message);
    }

    public static function forbidden(string $message = "Yetkiniz yok.\n"): void
    {
        self::text(403, $message);
    }

    public static function unauthorized(string $message = "Giriş gerekli.\n"): void
    {
        self::text(401, $message);
    }

    public static function sendFilePdf(string $absolutePath, string $downloadName): void
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            self::notFound();
            return;
        }
        http_response_code(200);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $downloadName . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($absolutePath);
    }
}

