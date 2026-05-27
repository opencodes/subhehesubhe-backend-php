<?php

declare(strict_types=1);

namespace App\Utils;

final class ApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 400,
    ) {
        parent::__construct($message);
    }
}
