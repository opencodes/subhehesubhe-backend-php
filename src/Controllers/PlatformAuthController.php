<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\AppContext;
use App\Services\PlatformAccountService;
use App\Utils\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PlatformAuthController
{
    public function signIn(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $username = (string) ($body['username'] ?? '');
        $password = (string) ($body['password'] ?? '');

        $session = $this->platform()->signIn($username, $password);

        return JsonResponse::ok($session);
    }

    public function listAdmins(ServerRequestInterface $request): ResponseInterface
    {
        return JsonResponse::ok(['admins' => $this->platform()->listAdmins()]);
    }

    public function createAdmin(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $auth = (array) $request->getAttribute('auth');
        $rootId = (string) ($auth['sub'] ?? '');

        $admin = $this->platform()->createAdmin(
            (string) ($body['username'] ?? ''),
            (string) ($body['password'] ?? ''),
            (string) ($body['name'] ?? ''),
            (string) ($body['email'] ?? ''),
            $rootId
        );

        return JsonResponse::ok(['admin' => $admin], 201);
    }

    public function updateAdmin(ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $request->getAttribute('id');
        $body = (array) ($request->getParsedBody() ?? []);

        $admin = $this->platform()->updateAdmin($id, $body);

        return JsonResponse::ok(['admin' => $admin]);
    }

    private function platform(): PlatformAccountService
    {
        $ctx = AppContext::boot();

        return new PlatformAccountService($ctx->mongo, $ctx->auth);
    }
}
