<?php
declare(strict_types=1);

namespace App;

final class Config
{
    private static array $values = [];
    private static bool $loaded = false;

    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        $envPath = dirname(__DIR__) . '/.env';
        if (!is_file($envPath)) {
            throw new \RuntimeException(".env introuvable à $envPath");
        }

        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            // Strip surrounding quotes if present.
            if (strlen($value) >= 2 && (
                ($value[0] === '"' && $value[-1] === '"') ||
                ($value[0] === "'" && $value[-1] === "'")
            )) {
                $value = substr($value, 1, -1);
            }
            self::$values[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        self::load();
        return self::$values[$key] ?? $default;
    }

    public static function required(string $key): string
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            throw new \RuntimeException("Variable d'environnement manquante : $key");
        }
        return $value;
    }

    /** @return string[] */
    public static function list(string $key): array
    {
        $raw = self::get($key, '');
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map(
            static fn($v) => strtolower(trim($v)),
            explode(',', $raw)
        )));
    }
}
