<?php
declare(strict_types=1);

require __DIR__ . '/http.php';
require __DIR__ . '/env.php';
require __DIR__ . '/db.php';
require __DIR__ . '/view.php';
require __DIR__ . '/csrf.php';
require __DIR__ . '/riba_report.php';
require __DIR__ . '/xlsx_export.php';
require __DIR__ . '/mailer.php';

// Ortam ayarları: önce .env, yoksa config/env okunur.
$root = dirname(__DIR__);

// Ortam bazlı hata yönetimi
$env = \App\Env::get('APP_ENV', 'production');
if ($env === 'production') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('log_errors', '1');
    $logPath = $root . '/storage/logs/php-errors.log';
    if (!is_dir(dirname($logPath))) {
        @mkdir(dirname($logPath), 0755, true);
    }
    ini_set('error_log', $logPath);
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}
\App\Env::load([
    $root . '/.env',
    $root . '/config/env',
]);

// Session (okul admini ve site admini için)
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
           (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
           (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => $isHttps, // HTTPS kontrolü (Google Cloud için)
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
