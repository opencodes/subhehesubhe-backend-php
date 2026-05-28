<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Utils\ApiException;
use App\Utils\BsonSerializer;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;

final class PlatformAccountService
{
    public function __construct(
        private readonly MongoService $mongo,
        private readonly AuthService $auth,
    ) {
    }

    public function ensureRootAccount(): void
    {
        $username = strtolower(trim(Env::string('ROOT_USERNAME', 'root')));
        $password = Env::string('ROOT_PASSWORD', '');
        if ($username === '' || $password === '') {
            return;
        }

        $collection = $this->collection();
        $existing = $collection->findOne(['username' => $username, 'role' => 'root']);
        if ($existing !== null) {
            return;
        }

        $collection->insertOne([
            'username' => $username,
            'passwordHash' => $this->hashPassword($password),
            'role' => 'root',
            'name' => 'Root Administrator',
            'email' => '',
            'active' => true,
            'createdBy' => null,
            'createdAt' => new UTCDateTime(),
            'updatedAt' => new UTCDateTime(),
        ]);
    }

    /** @return array{token: string, user: array<string, mixed>} */
    public function signIn(string $username, string $password): array
    {
        $this->ensureRootAccount();

        $normalized = $this->normalizeUsername($username);
        if ($normalized === '' || $password === '') {
            throw new ApiException('Username and password are required', 422);
        }

        $account = $this->collection()->findOne(['username' => $normalized]);
        if ($account === null) {
            throw new ApiException('Invalid username or password', 401);
        }

        if (($account['active'] ?? true) !== true) {
            throw new ApiException('This account has been deactivated', 403);
        }

        $hash = (string) ($account['passwordHash'] ?? '');
        if ($hash === '' || !password_verify($password, $hash)) {
            throw new ApiException('Invalid username or password', 401);
        }

        return $this->sessionForAccount((array) $account);
    }

    /** @return array{token: string, user: array<string, mixed>} */
    public function sessionForAccount(array $account): array
    {
        $accountId = (string) $account['_id'];
        $role = (string) ($account['role'] ?? 'admin');

        $token = $this->auth->issueToken([
            'sub' => $accountId,
            'role' => $role,
            'accountType' => 'platform',
        ]);

        return [
            'token' => $token,
            'user' => $this->serializeAccount($account),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function listAdmins(): array
    {
        $items = [];
        $cursor = $this->collection()->find(
            ['role' => 'admin'],
            ['sort' => ['createdAt' => -1]]
        );
        foreach ($cursor as $doc) {
            $items[] = $this->serializeAccount((array) $doc);
        }

        return $items;
    }

    /** @return array<string, mixed> */
    public function createAdmin(
        string $username,
        string $password,
        string $name,
        string $email,
        string $createdById
    ): array {
        $normalized = $this->normalizeUsername($username);
        $this->assertPasswordStrength($password);

        if ($normalized === '' || trim($name) === '') {
            throw new ApiException('Username and display name are required', 422);
        }

        if ($this->collection()->findOne(['username' => $normalized]) !== null) {
            throw new ApiException('Username already exists', 409);
        }

        $doc = [
            'username' => $normalized,
            'passwordHash' => $this->hashPassword($password),
            'role' => 'admin',
            'name' => trim($name),
            'email' => strtolower(trim($email)),
            'active' => true,
            'createdBy' => $createdById,
            'createdAt' => new UTCDateTime(),
            'updatedAt' => new UTCDateTime(),
        ];
        $result = $this->collection()->insertOne($doc);
        $doc['_id'] = $result->getInsertedId();

        return $this->serializeAccount($doc);
    }

    /** @param array<string, mixed> $patch */
    public function updateAdmin(string $accountId, array $patch): array
    {
        $update = ['updatedAt' => new UTCDateTime()];

        if (isset($patch['name']) && trim((string) $patch['name']) !== '') {
            $update['name'] = trim((string) $patch['name']);
        }
        if (array_key_exists('email', $patch)) {
            $update['email'] = strtolower(trim((string) $patch['email']));
        }
        if (array_key_exists('active', $patch)) {
            $update['active'] = (bool) $patch['active'];
        }
        if (!empty($patch['password'])) {
            $this->assertPasswordStrength((string) $patch['password']);
            $update['passwordHash'] = $this->hashPassword((string) $patch['password']);
        }

        $result = $this->collection()->updateOne(
            ['_id' => new ObjectId($accountId), 'role' => 'admin'],
            ['$set' => $update]
        );

        if ($result->getMatchedCount() === 0) {
            throw new ApiException('Admin account not found', 404);
        }

        $doc = $this->collection()->findOne(['_id' => new ObjectId($accountId)]);

        return $this->serializeAccount((array) $doc);
    }

    public function getAccountById(string $accountId): ?array
    {
        try {
            $doc = $this->collection()->findOne(['_id' => new ObjectId($accountId)]);
        } catch (\Throwable) {
            return null;
        }

        return $doc ? $this->serializeAccount((array) $doc) : null;
    }

    /** @param array<string, mixed> $account */
    public function serializeAccount(array $account): array
    {
        $normalized = BsonSerializer::normalize($account);

        return [
            'id' => (string) ($normalized['_id'] ?? $normalized['id'] ?? ''),
            'username' => (string) ($normalized['username'] ?? ''),
            'role' => (string) ($normalized['role'] ?? 'admin'),
            'name' => (string) ($normalized['name'] ?? ''),
            'email' => (string) ($normalized['email'] ?? ''),
            'active' => (bool) ($normalized['active'] ?? true),
            'createdAt' => $normalized['createdAt'] ?? null,
        ];
    }

    private function collection(): Collection
    {
        return $this->mongo->collection('platform_accounts');
    }

    private function normalizeUsername(string $username): string
    {
        return strtolower(trim($username));
    }

    private function hashPassword(string $password): string
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($hash === false) {
            throw new ApiException('Could not hash password', 500);
        }

        return $hash;
    }

    private function assertPasswordStrength(string $password): void
    {
        if (strlen($password) < 8) {
            throw new ApiException('Password must be at least 8 characters', 422);
        }
    }
}
