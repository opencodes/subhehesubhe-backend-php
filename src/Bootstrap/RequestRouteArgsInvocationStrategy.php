<?php

declare(strict_types=1);

namespace App\Bootstrap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\InvocationStrategyInterface;

/**
 * Invokes route callables as ($request) or ($request, $routeArguments).
 * Matches App controller method signatures (no Response argument).
 */
final class RequestRouteArgsInvocationStrategy implements InvocationStrategyInterface
{
    /**
     * @param array<string, string> $routeArguments
     */
    public function __invoke(
        callable $callable,
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $routeArguments
    ): ResponseInterface {
        foreach ($routeArguments as $key => $value) {
            $request = $request->withAttribute($key, $value);
        }

        if ($routeArguments === []) {
            /** @var ResponseInterface */
            return $callable($request);
        }

        /** @var ResponseInterface */
        return $callable($request, $routeArguments);
    }
}
