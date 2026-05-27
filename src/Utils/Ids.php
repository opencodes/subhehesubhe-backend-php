<?php

declare(strict_types=1);

namespace App\Utils;

final class Ids
{
    public static function new(string $prefix): string
    {
        return $prefix . '-' . bin2hex(random_bytes(4));
    }
}
