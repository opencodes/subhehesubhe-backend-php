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
        $phone = (string) ($body['phone'] ?? '');
        $email = (string) ($body['email'] ?? '');
        if ($phone === '' || $email === '') {
            throw new ApiException('phone and email are required', 422);
        }

        $session = AppContext::boot()->auth->signInCustomer($phone, $email, [
            'name' => $body['name'] ?? null,
            'customerType' => $body['customerType'] ?? 'standard',
        ]);

        return JsonResponse::ok($session);
    }

    public function signInVendor(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $phone = (string) ($body['phone'] ?? '');
        $email = (string) ($body['email'] ?? '');
        if ($phone === '' || $email === '') {
            throw new ApiException('phone and email are required', 422);
        }

        $session = AppContext::boot()->auth->signInVendor($phone, $email, [
            'vendorId' => $body['vendorId'] ?? null,
            'businessName' => $body['businessName'] ?? null,
            'contactName' => $body['contactName'] ?? null,
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
        $userId = (string) $request->getAttribute('userId');
        $user = AppContext::boot()->auth->getUserById($userId);
        if ($user === null) {
            throw new ApiException('User not found', 404);
        }

        return JsonResponse::ok(['user' => $user]);
    }
}
