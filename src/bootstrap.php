<?php
declare(strict_types=1);

require __DIR__ . '/http.php';
require __DIR__ . '/env.php';
require __DIR__ . '/db.php';
require __DIR__ . '/view.php';
require __DIR__ . '/csrf.php';
require __DIR__ . '/riba_report.php';
require __DIR__ . '/xlsx_export.php';

// Basit hata görünürlüğü (prod ortamda kapatılacak; şimdilik kurulum aşaması)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Ortam ayarları: önce .env, yoksa config/env okunur.
$root = dirname(__DIR__);
\App\Env::load([
    $root . '/.env',
    $root . '/config/env',
]);

// Session (okul admini ve site admini için)
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
