<?php
declare(strict_types=1);

namespace App;

final class View
{
    public static function e(?string $value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function layout(string $title, string $bodyHtml): string
    {
        $t = self::e($title);
        return <<<HTML
<!doctype html>
<html lang="tr">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>{$t}</title>
  </head>
  <body>
    {$bodyHtml}
  </body>
</html>
HTML;
    }
}

