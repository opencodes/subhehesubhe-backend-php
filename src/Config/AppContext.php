<?php

declare(strict_types=1);

namespace App\Config;

use App\Services\AuthService;
use App\Services\MongoService;

/** Lightweight service locator for Slim routes (no extra DI package). */
final class AppContext
{
    private static ?self $instance = null;

    private function __construct(
        public readonly MongoService $mongo,
        public readonly AuthService $auth,
    ) {
    }

    public static function boot(): self
    {
        if (self::$instance === null) {
            $mongo = new MongoService();
            self::$instance = new self($mongo, new AuthService($mongo));
        }

        return self::$instance;
    }
}
