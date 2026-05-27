<?php

declare(strict_types=1);

namespace App\Bootstrap;

use App\Config\AppContext;
use App\Middleware\CorsMiddleware;
use App\Middleware\JsonBodyParserMiddleware;
use App\Routes\ApiRoutes;
use Slim\Factory\AppFactory as SlimAppFactory;

final class AppFactory
{
    public static function create(): \Slim\App
    {
        AppContext::boot();

        $app = SlimAppFactory::create();
        $app->addRoutingMiddleware();
        $app->addBodyParsingMiddleware();
        $app->add(new JsonBodyParserMiddleware());

        $displayErrors = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $errorMiddleware = $app->addErrorMiddleware($displayErrors, true, true);

        $errorMiddleware->setDefaultErrorHandler(function (
            \Psr\Http\Message\ServerRequestInterface $request,
            \Throwable $exception
        ) use ($app, $displayErrors) {
            $status = method_exists($exception, 'getStatusCode')
                ? (int) $exception->getStatusCode()
                : 500;

            if (!method_exists($exception, 'getStatusCode')) {
                $status = $exception instanceof \App\Utils\ApiException
                    ? $exception->statusCode
                    : 500;
            }

            $payload = [
                'success' => false,
                'error' => $exception->getMessage(),
            ];
            if ($displayErrors && $status >= 500) {
                $payload['trace'] = $exception->getTraceAsString();
            }

            $response = $app->getResponseFactory()->createResponse($status);
            $response->getBody()->write((string) json_encode($payload));

            $response = $response->withHeader('Content-Type', 'application/json');

            return CorsMiddleware::apply($response, $request->getHeaderLine('Origin'));
        });

        ApiRoutes::register($app);

        // Outermost middleware (added last) — must run before routing so OPTIONS preflight succeeds
        $app->add(new CorsMiddleware());

        return $app;
    }
}
