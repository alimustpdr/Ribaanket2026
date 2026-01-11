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
}

