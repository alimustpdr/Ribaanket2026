<?php
declare(strict_types=1);

namespace App;

final class Env
{
    /**
     * Önce gerçek ortam değişkenlerini (getenv) kullanır.
     * Eğer yoksa, verilen dosyalardan (key=value) yükler.
     */
    public static function load(array $paths): void
    {
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }

            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!is_array($lines)) {
                continue;
            }

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                $pos = strpos($line, '=');
                if ($pos === false) {
                    continue;
                }
                $key = trim(substr($line, 0, $pos));
                $val = trim(substr($line, $pos + 1));
                if ($key === '') {
                    continue;
                }

                // Zaten ortamda varsa ezmeyelim.
                if (getenv($key) !== false) {
                    continue;
                }

                putenv($key . '=' . $val);
                $_ENV[$key] = $val;
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $val = getenv($key);
        if ($val === false) {
            return $default;
        }
        return $val;
    }
}

