<?php
declare(strict_types=1);

require __DIR__ . '/http.php';

// Basit hata görünürlüğü (prod ortamda kapatılacak; şimdilik kurulum aşaması)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// İleride: config yükleme, DB bağlantısı, router vs.
