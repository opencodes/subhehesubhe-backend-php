<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Config\Env;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class CorsMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');

        if ($request->getMethod() === 'OPTIONS') {
            $response = new Response(204);
            return self::apply($response, $origin);
        }

        $response = $handler->handle($request);

        return self::apply($response, $origin);
    }

    public static function apply(ResponseInterface $response, string $requestOrigin): ResponseInterface
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', self::resolveAllowOrigin($requestOrigin))
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, Accept')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
            ->withHeader('Access-Control-Max-Age', '86400');
    }

    public static function resolveAllowOrigin(string $requestOrigin): string
    {
        $allowed = Env::csv('CORS_ORIGINS');

        if ($requestOrigin !== '' && in_array($requestOrigin, $allowed, true)) {
            return $requestOrigin;
        }

        // Local dev: allow any localhost / 127.0.0.1 port when at least one local origin is configured
        if ($requestOrigin !== '' && self::isLocalDevOrigin($requestOrigin) && self::allowsLocalDev($allowed)) {
            return $requestOrigin;
        }

        return $allowed[0] ?? '*';
    }

    /** @param list<string> $allowed */
    private static function allowsLocalDev(array $allowed): bool
    {
        foreach ($allowed as $origin) {
            if (self::isLocalDevOrigin($origin)) {
                return true;
            }
        }

        return false;
    }

    private static function isLocalDevOrigin(string $origin): bool
    {
        return (bool) preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin);
    }
}
