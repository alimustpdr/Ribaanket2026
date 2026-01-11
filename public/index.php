<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Http;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/health') {
    Http::text(200, "ok\n");
    exit;
}

// Minimal başlangıç ekranı (ileride panel/anket rotaları eklenecek)
Http::html(200, <<<HTML
<!doctype html>
<html lang="tr">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>RİBA</title>
  </head>
  <body>
    <h1>RİBA Sistemi</h1>
    <p>Kurulum iskeleti hazır. Sonraki adımda okul üyelik/aktivasyon ve anket bağlantıları eklenecek.</p>
    <p>Sağlık kontrolü: <a href="/health">/health</a></p>
  </body>
</html>
HTML);
