<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\AppContext;
use App\Utils\ApiException;
use App\Utils\Ids;
use App\Utils\JsonResponse;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class UserController
{
    public function getProfile(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->requireUser($request);
        return JsonResponse::ok(['profile' => $this->toProfile($user)]);
    }

    public function updateProfile(ServerRequestInterface $request): ResponseInterface
    {
        $userId = (string) $request->getAttribute('userId');
        $body = (array) ($request->getParsedBody() ?? []);
        $allowed = ['name', 'email', 'phone', 'walletBalance', 'royaltyPoints', 'customerType'];
        $update = array_intersect_key($body, array_flip($allowed));
        $update['updatedAt'] = new UTCDateTime();

        AppContext::boot()->mongo->collection('users')->updateOne(
            ['_id' => new ObjectId($userId)],
            ['$set' => $update]
        );

        $user = $this->requireUser($request);
        return JsonResponse::ok(['profile' => $this->toProfile($user)]);
    }

    public function addAddress(ServerRequestInterface $request): ResponseInterface
    {
        $userId = (string) $request->getAttribute('userId');
        $body = (array) ($request->getParsedBody() ?? []);
        $address = [
            'id' => Ids::new('addr'),
            'type' => $body['type'] ?? 'Home',
            'addressLine' => (string) ($body['addressLine'] ?? ''),
            'landmark' => $body['landmark'] ?? null,
            'city' => (string) ($body['city'] ?? ''),
        ];

        AppContext::boot()->mongo->collection('users')->updateOne(
            ['_id' => new ObjectId($userId)],
            ['$push' => ['addresses' => $address]]
        );

        return JsonResponse::ok(['address' => $address], 201);
    }

    public function listCustomers(ServerRequestInterface $request): ResponseInterface
    {
        $items = [];
        $cursor = AppContext::boot()->mongo->collection('users')->find(
            ['role' => 'customer'],
            ['sort' => ['createdAt' => -1], 'limit' => 500]
        );
        foreach ($cursor as $doc) {
            $u = (array) $doc;
            $items[] = [
                'id' => (string) $u['_id'],
                'name' => $u['name'] ?? '',
                'email' => $u['email'] ?? '',
                'phone' => $u['phone'] ?? '',
                'customerType' => $u['customerType'] ?? 'standard',
                'ordersCount' => is_array($u['orders'] ?? null) ? count($u['orders']) : 0,
            ];
        }

        return JsonResponse::ok(['customers' => $items]);
    }

    /** @return array<string, mixed> */
    private function requireUser(ServerRequestInterface $request): array
    {
        $userId = (string) $request->getAttribute('userId');
        $user = AppContext::boot()->auth->getUserById($userId);
        if ($user === null) {
            throw new ApiException('User not found', 404);
        }

        return $user;
    }

    /** @param array<string, mixed> $user */
    private function toProfile(array $user): array
    {
        return [
            'name' => $user['name'] ?? '',
            'email' => $user['email'] ?? '',
            'phone' => $user['phone'] ?? '',
            'customerType' => $user['customerType'] ?? 'standard',
            'walletBalance' => (int) ($user['walletBalance'] ?? 0),
            'royaltyPoints' => (int) ($user['royaltyPoints'] ?? 0),
            'addresses' => $user['addresses'] ?? [],
            'orders' => $user['orders'] ?? [],
            'supportTickets' => $user['supportTickets'] ?? [],
        ];
    }
}
