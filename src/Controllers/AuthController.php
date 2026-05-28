<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\AppContext;
use App\Utils\ApiException;
use App\Utils\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AuthController
{
    public function signInCustomer(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $phone = trim((string) ($body['phone'] ?? ''));
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        if (($phone === '' && $email === '') || $password === '') {
            throw new ApiException('Email or phone and password are required', 422);
        }

        $session = AppContext::boot()->auth->signInCustomer($phone, $email, [
            'name' => $body['name'] ?? null,
            'customerType' => $body['customerType'] ?? 'standard',
            'password' => $password,
        ]);

        return JsonResponse::ok($session);
    }

    public function signInVendor(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $phone = trim((string) ($body['phone'] ?? ''));
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        if (($phone === '' && $email === '') || $password === '') {
            throw new ApiException('Email or phone and password are required', 422);
        }

        $session = AppContext::boot()->auth->signInVendor($phone, $email, [
            'vendorId' => $body['vendorId'] ?? null,
            'businessName' => $body['businessName'] ?? null,
            'contactName' => $body['contactName'] ?? null,
            'password' => $password,
        ]);

        return JsonResponse::ok([
            'token' => $session['token'],
            'vendorSession' => [
                'vendorId' => $session['user']['vendorId'],
                'businessName' => $session['user']['businessName'],
                'contactName' => $session['user']['contactName'],
                'email' => $session['user']['email'],
                'phone' => $session['user']['phone'],
            ],
            'user' => $session['user'],
        ]);
    }

    public function registerPlanner(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $session = AppContext::boot()->auth->registerEventPlanner($body);

        return JsonResponse::ok($session, 201);
    }

    public function me(ServerRequestInterface $request): ResponseInterface
    {
        $auth = (array) $request->getAttribute('auth');
        $userId = (string) $request->getAttribute('userId');
        $ctx = AppContext::boot();

        if (($auth['accountType'] ?? '') === 'platform') {
            $platform = new \App\Services\PlatformAccountService($ctx->mongo, $ctx->auth);
            $account = $platform->getAccountById($userId);
            if ($account === null) {
                throw new ApiException('Account not found', 404);
            }

            return JsonResponse::ok(['user' => $account]);
        }

        $user = $ctx->auth->getUserById($userId);
        if ($user === null) {
            throw new ApiException('User not found', 404);
        }

        return JsonResponse::ok(['user' => $user]);
    }
}
