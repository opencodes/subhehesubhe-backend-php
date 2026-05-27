<?php

declare(strict_types=1);

namespace App\Config;

final class Env
{
    public static function string(string $key, string $default = ''): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
        return is_string($value) ? $value : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
        return is_numeric($value) ? (int) $value : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** @return list<string> */
    public static function csv(string $key): array
    {
        $raw = self::string($key);
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
