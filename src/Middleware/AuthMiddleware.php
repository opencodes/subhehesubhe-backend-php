<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Config\AppContext;
use App\Utils\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AuthMiddleware implements MiddlewareInterface
{
    /** @param list<string> $roles */
    public function __construct(private readonly array $roles = [])
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            throw new ApiException('Authorization required', 401);
        }

        $claims = AppContext::boot()->auth->verifyToken($m[1]);
        $role = (string) ($claims['role'] ?? '');

        if ($this->roles !== [] && !in_array($role, $this->roles, true)) {
            throw new ApiException('Forbidden', 403);
        }

        $request = $request
            ->withAttribute('auth', $claims)
            ->withAttribute('userId', (string) ($claims['sub'] ?? ''));

        return $handler->handle($request);
    }
}
