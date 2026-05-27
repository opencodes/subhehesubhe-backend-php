<?php

declare(strict_types=1);

namespace App\Utils;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Psr7\Response as SlimResponse;

final class JsonResponse
{
    /** @param mixed $data */
    public static function ok($data, int $status = 200): Response
    {
        $response = new SlimResponse($status);
        $response->getBody()->write((string) json_encode([
            'success' => true,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json');
    }

    public static function message(string $message, int $status = 200): Response
    {
        return self::ok(['message' => $message], $status);
    }
}
