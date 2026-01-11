<?php
declare(strict_types=1);

namespace App;

final class Csrf
{
    public static function token(): string
    {
        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function input(): string
    {
        $t = View::e(self::token());
        return "<input type=\"hidden\" name=\"csrf_token\" value=\"{$t}\" />";
    }

    public static function validatePost(): void
    {
        $sent = $_POST['csrf_token'] ?? '';
        $real = $_SESSION['csrf_token'] ?? '';
        if (!is_string($sent) || !is_string($real) || $sent === '' || $real === '' || !hash_equals($real, $sent)) {
            Http::text(400, "Geçersiz istek (CSRF).\n");
            exit;
        }
    }
}

