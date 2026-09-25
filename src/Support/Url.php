<?php
declare(strict_types=1);

namespace App\Support;

final class Url
{
    public static function base(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return "$scheme://$host";
    }

    public static function to(string $path): string
    {
        return self::base() . '/' . ltrim($path, '/');
    }
}
