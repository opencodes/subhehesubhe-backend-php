<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Utils\ApiException;
use App\Utils\Ids;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

final class AuthService
{
    public function __construct(private readonly MongoService $mongo)
    {
    }

    /** @param array<string, mixed> $claims */
    public function issueToken(array $claims): string
    {
        $now = time();
        $payload = array_merge($claims, [
            'iat' => $now,
            'exp' => $now + Env::int('JWT_TTL_SECONDS', 604800),
        ]);

        return JWT::encode($payload, Env::string('JWT_SECRET'), 'HS256');
    }

    /** @return array<string, mixed> */
    public function verifyToken(string $token): array
    {
        try {
            $decoded = JWT::decode($token, new Key(Env::string('JWT_SECRET'), 'HS256'));
            return (array) $decoded;
        } catch (\Throwable $e) {
            throw new ApiException('Invalid or expired token', 401);
        }
    }

    /** @param array<string, mixed> $profile */
    public function signInCustomer(string $phone, string $email, array $profile = []): array
    {
        $users = $this->mongo->collection('users');
        $normalizedPhone = $this->normalizePhone($phone);
        $normalizedEmail = strtolower(trim($email));

        $user = $users->findOne([
            '$or' => [
                ['phone' => $normalizedPhone],
                ['email' => $normalizedEmail],
            ],
        ]);

        if ($user === null) {
            $doc = [
                '_id' => new ObjectId(),
                'role' => 'customer',
                'customerType' => $profile['customerType'] ?? 'standard',
                'phone' => $normalizedPhone,
                'email' => $normalizedEmail,
                'name' => $profile['name'] ?? 'Guest',
                'walletBalance' => 0,
                'royaltyPoints' => 0,
                'addresses' => [],
                'orders' => [],
                'supportTickets' => [],
                'createdAt' => new UTCDateTime(),
                'updatedAt' => new UTCDateTime(),
            ];
            $users->insertOne($doc);
            $user = $doc;
        } else {
            $users->updateOne(
                ['_id' => $user['_id']],
                ['$set' => array_filter([
                    'phone' => $normalizedPhone,
                    'email' => $normalizedEmail,
                    'name' => $profile['name'] ?? null,
                    'customerType' => $profile['customerType'] ?? null,
                    'updatedAt' => new UTCDateTime(),
                ], static fn ($v) => $v !== null)]
            );
            $user = $users->findOne(['_id' => $user['_id']]);
        }

        return $this->sessionForUser($user);
    }

    /** @param array<string, mixed> $vendorMeta */
    public function signInVendor(string $phone, string $email, array $vendorMeta = []): array
    {
        $users = $this->mongo->collection('users');
        $vendors = $this->mongo->collection('vendors');
        $normalizedPhone = $this->normalizePhone($phone);
        $normalizedEmail = strtolower(trim($email));

        $user = $users->findOne([
            'role' => 'vendor',
            '$or' => [
                ['phone' => $normalizedPhone],
                ['email' => $normalizedEmail],
            ],
        ]);

        $vendorId = $vendorMeta['vendorId'] ?? null;

        if ($user === null) {
            if ($vendorId === null) {
                $fallback = $vendors->findOne([], ['sort' => ['rating' => -1]]);
                $vendorId = $fallback ? (string) $fallback['listingId'] : 'vn-4';
            }

            $user = [
                '_id' => new ObjectId(),
                'role' => 'vendor',
                'vendorId' => $vendorId,
                'phone' => $normalizedPhone,
                'email' => $normalizedEmail,
                'contactName' => $vendorMeta['contactName'] ?? 'Vendor Contact',
                'businessName' => $vendorMeta['businessName'] ?? 'My Business',
                'createdAt' => new UTCDateTime(),
                'updatedAt' => new UTCDateTime(),
            ];
            $users->insertOne($user);
        }

        return $this->sessionForUser($user);
    }

    /** @param array<string, mixed> $payload */
    public function registerEventPlanner(array $payload): array
    {
        $users = $this->mongo->collection('users');
        $planner = $this->mongo->collection('planner_workspaces');

        $phone = $this->normalizePhone((string) ($payload['phone'] ?? ''));
        $email = strtolower(trim((string) ($payload['email'] ?? '')));

        if ($phone === '' || $email === '') {
            throw new ApiException('Phone and email are required', 422);
        }

        $existing = $users->findOne([
            '$or' => [['phone' => $phone], ['email' => $email]],
        ]);

        if ($existing !== null && ($existing['customerType'] ?? '') === 'event-planner') {
            return $this->sessionForUser($existing);
        }

        $userId = new ObjectId();
        $user = [
            '_id' => $userId,
            'role' => 'customer',
            'customerType' => 'event-planner',
            'phone' => $phone,
            'email' => $email,
            'name' => (string) ($payload['fullName'] ?? 'Event Planner'),
            'companyName' => $payload['companyName'] ?? null,
            'primaryEventType' => $payload['primaryEventType'] ?? null,
            'city' => $payload['city'] ?? null,
            'serviceCities' => $payload['serviceCities'] ?? null,
            'bio' => $payload['bio'] ?? '',
            'walletBalance' => 0,
            'royaltyPoints' => 0,
            'addresses' => [],
            'orders' => [],
            'supportTickets' => [],
            'createdAt' => new UTCDateTime(),
            'updatedAt' => new UTCDateTime(),
        ];
        $users->insertOne($user);

        $draft = $payload['draftEvent'] ?? [];
        $eventId = Ids::new('evt');
        $event = [
            'id' => $eventId,
            'name' => (string) ($draft['eventName'] ?? $payload['primaryEventType'] ?? 'My Event'),
            'date' => (string) ($draft['date'] ?? ''),
            'description' => (string) ($payload['bio'] ?? ''),
            'isActive' => true,
        ];

        $planner->insertOne([
            'userId' => (string) $userId,
            'events' => [$event],
            'subEvents' => [],
            'rituals' => [],
            'guests' => [],
            'vendors' => [],
            'feast' => [],
            'misc' => [],
            'bartan' => [],
            'cylinders' => [],
            'expenses' => [],
            'chuman' => [],
            'budgetLimit' => 0,
            'estVillagers' => 0,
            'estRelatives' => 0,
            'updatedAt' => new UTCDateTime(),
        ]);

        return $this->sessionForUser($user);
    }

    /** @param array<string, mixed>|object $user */
    public function sessionForUser(array|object $user): array
    {
        $u = (array) $user;
        $userId = (string) $u['_id'];
        $role = (string) ($u['role'] ?? 'customer');

        $token = $this->issueToken([
            'sub' => $userId,
            'role' => $role,
            'customerType' => $u['customerType'] ?? 'standard',
            'vendorId' => $u['vendorId'] ?? null,
        ]);

        return [
            'token' => $token,
            'user' => $this->serializeUser($u),
        ];
    }

    public function getUserById(string $userId): ?array
    {
        try {
            $doc = $this->mongo->collection('users')->findOne(['_id' => new ObjectId($userId)]);
        } catch (\Throwable) {
            return null;
        }

        return $doc ? $this->serializeUser((array) $doc) : null;
    }

    /** @param array<string, mixed> $user */
    public function serializeUser(array $user): array
    {
        return [
            'id' => (string) $user['_id'],
            'role' => $user['role'] ?? 'customer',
            'customerType' => $user['customerType'] ?? 'standard',
            'name' => $user['name'] ?? '',
            'email' => $user['email'] ?? '',
            'phone' => $user['phone'] ?? '',
            'companyName' => $user['companyName'] ?? null,
            'walletBalance' => (int) ($user['walletBalance'] ?? 0),
            'royaltyPoints' => (int) ($user['royaltyPoints'] ?? 0),
            'addresses' => $user['addresses'] ?? [],
            'orders' => $user['orders'] ?? [],
            'supportTickets' => $user['supportTickets'] ?? [],
            'vendorId' => $user['vendorId'] ?? null,
            'businessName' => $user['businessName'] ?? null,
            'contactName' => $user['contactName'] ?? null,
        ];
    }

    public function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) === 10) {
            return '+91 ' . substr($digits, 0, 5) . ' ' . substr($digits, 5);
        }

        return trim($phone);
    }
}
