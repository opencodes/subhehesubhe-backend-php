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
        $password = (string) ($profile['password'] ?? '');

        $or = [];
        if (trim($phone) !== '') {
            $or[] = ['phone' => $normalizedPhone];
        }
        if (trim($email) !== '') {
            $or[] = ['email' => $normalizedEmail];
        }
        if ($or === []) {
            throw new ApiException('Email or phone is required', 422);
        }

        $user = $users->findOne([
            'role' => 'customer',
            '$or' => $or,
        ]);

        if ($user === null) {
            $defaultPassword = Env::string('CUSTOMER_DEFAULT_PASSWORD', '');
            if ($defaultPassword === '') {
                throw new ApiException('Customer default password is not configured', 500);
            }
            $this->assertPasswordStrength($defaultPassword);
            if ($password === '' || $password !== $defaultPassword) {
                throw new ApiException('Invalid username or password', 401);
            }

            $doc = [
                '_id' => new ObjectId(),
                'role' => 'customer',
                'customerType' => $profile['customerType'] ?? 'standard',
                'phone' => $normalizedPhone,
                'email' => $normalizedEmail,
                'name' => $profile['name'] ?? 'Guest',
                'passwordHash' => $this->hashPassword($defaultPassword),
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
            $hash = (string) ($user['passwordHash'] ?? '');
            if ($hash === '') {
                // Migration path for previously passwordless customers: allow default password once.
                $defaultPassword = Env::string('CUSTOMER_DEFAULT_PASSWORD', '');
                if ($defaultPassword === '' || $password === '' || $password !== $defaultPassword) {
                    throw new ApiException('Invalid username or password', 401);
                }
                $users->updateOne(
                    ['_id' => $user['_id']],
                    ['$set' => ['passwordHash' => $this->hashPassword($defaultPassword)]]
                );
            } else {
                if ($password === '' || !password_verify($password, $hash)) {
                    throw new ApiException('Invalid username or password', 401);
                }
            }

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
        $password = (string) ($vendorMeta['password'] ?? '');

        $or = [];
        if (trim($phone) !== '') {
            $or[] = ['phone' => $normalizedPhone];
        }
        if (trim($email) !== '') {
            $or[] = ['email' => $normalizedEmail];
        }
        if ($or === []) {
            throw new ApiException('Email or phone is required', 422);
        }

        $user = $users->findOne([
            'role' => 'vendor',
            '$or' => $or,
        ]);

        $vendorId = $vendorMeta['vendorId'] ?? null;

        if ($user === null) {
            if ($vendorId === null || $vendorId === '') {
                $vendorOr = [];
                if ($normalizedEmail !== '') {
                    $vendorOr[] = ['contactEmail' => $normalizedEmail];
                }
                if ($normalizedPhone !== '') {
                    $vendorOr[] = ['contactPhone' => $normalizedPhone];
                }
                if ($vendorOr !== []) {
                    $listing = $vendors->findOne(
                        ['$or' => $vendorOr],
                        ['sort' => ['createdAt' => -1]]
                    );
                    if ($listing !== null) {
                        $vendorId = (string) $listing['listingId'];
                    }
                }
            }

            if ($vendorId === null || $vendorId === '') {
                throw new ApiException('Invalid username or password', 401);
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
        } else {
            $hash = (string) ($user['passwordHash'] ?? '');
            if ($hash !== '') {
                if ($password === '' || !password_verify($password, $hash)) {
                    throw new ApiException('Invalid username or password', 401);
                }
            }
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
        $password = (string) ($payload['password'] ?? '');
        $confirmPassword = (string) ($payload['confirmPassword'] ?? $password);

        if ($phone === '' || $email === '') {
            throw new ApiException('Phone and email are required', 422);
        }
        if ($password === '') {
            throw new ApiException('Password is required', 422);
        }
        if ($password !== $confirmPassword) {
            throw new ApiException('Passwords do not match', 422);
        }
        $this->assertPasswordStrength($password);

        $existing = $users->findOne([
            '$or' => [['phone' => $phone], ['email' => $email]],
        ]);

        if ($existing !== null) {
            if (($existing['customerType'] ?? '') === 'event-planner') {
                throw new ApiException(
                    'An account with this email or phone already exists. Please sign in.',
                    409
                );
            }
            throw new ApiException('Email or phone is already registered', 409);
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
            'passwordHash' => $this->hashPassword($password),
            'walletBalance' => 0,
            'royaltyPoints' => 0,
            'addresses' => [],
            'orders' => [],
            'supportTickets' => [],
            'createdAt' => new UTCDateTime(),
            'updatedAt' => new UTCDateTime(),
        ];
        $users->insertOne($user);

        $draft = is_array($payload['draftEvent'] ?? null) ? $payload['draftEvent'] : [];
        $eventId = Ids::new('evt');
        $location = trim((string) ($draft['location'] ?? ''));
        $eventType = (string) ($draft['eventType'] ?? $payload['primaryEventType'] ?? '');
        $bio = trim((string) ($payload['bio'] ?? ''));
        $descriptionParts = array_filter([
            $location !== '' ? 'Location: ' . $location : '',
            $eventType !== '' ? 'Type: ' . $eventType : '',
            $bio,
        ]);
        $event = [
            'id' => $eventId,
            'name' => (string) ($draft['eventName'] ?? $payload['primaryEventType'] ?? 'My Event'),
            'date' => (string) ($draft['date'] ?? ''),
            'location' => $location,
            'eventType' => $eventType,
            'description' => implode(' · ', $descriptionParts),
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

        return [
            'registered' => true,
            'user' => $this->serializeUser($user),
            'workspace' => [
                'eventId' => $eventId,
                'eventName' => $event['name'],
            ],
        ];
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

    public function hashPassword(string $password): string
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($hash === false) {
            throw new ApiException('Could not hash password', 500);
        }

        return $hash;
    }

    public function assertPasswordStrength(string $password): void
    {
        if (strlen($password) < 8) {
            throw new ApiException('Password must be at least 8 characters', 422);
        }
    }

}
