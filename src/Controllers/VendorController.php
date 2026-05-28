<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\AppContext;
use App\Config\Env;
use App\Utils\ApiException;
use App\Utils\BsonSerializer;
use App\Utils\Ids;
use App\Utils\JsonResponse;
use MongoDB\BSON\UTCDateTime;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class VendorController
{
    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $and = [$this->publicVisibilityFilter()];

        if (!empty($params['category'])) {
            $and[] = ['category' => (string) $params['category']];
        }
        if (!empty($params['city'])) {
            $city = (string) $params['city'];
            $cityRegex = preg_quote($city, '/');
            $and[] = [
                '$or' => [
                    ['location' => ['$regex' => $cityRegex, '$options' => 'i']],
                    ['district' => $city],
                    ['state' => $city],
                ],
            ];
        }
        if (!empty($params['q'])) {
            $q = preg_quote((string) $params['q'], '/');
            $and[] = [
                '$or' => [
                    ['name' => ['$regex' => $q, '$options' => 'i']],
                    ['location' => ['$regex' => $q, '$options' => 'i']],
                ],
            ];
        }

        $filter = count($and) === 1 ? $and[0] : ['$and' => $and];

        $items = [];
        $cursor = AppContext::boot()->mongo->collection('vendors')->find(
            $filter,
            ['sort' => ['rating' => -1]]
        );
        foreach ($cursor as $doc) {
            $items[] = $this->serializeVendor((array) $doc);
        }

        return JsonResponse::ok(['vendors' => $items]);
    }

    public function get(ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $request->getAttribute('id');
        $doc = AppContext::boot()->mongo->collection('vendors')->findOne([
            'listingId' => $id,
            '$or' => [
                ['status' => 'approved'],
                ['status' => ['$exists' => false]],
            ],
        ]);
        if ($doc === null) {
            throw new ApiException('Vendor not found', 404);
        }

        return JsonResponse::ok(['vendor' => $this->serializeVendor((array) $doc)]);
    }

    /** Vendor dashboard: own listing regardless of approval status. */
    public function getDashboard(ServerRequestInterface $request): ResponseInterface
    {
        $vendorId = (string) $request->getAttribute('id');
        $auth = (array) $request->getAttribute('auth');
        $role = (string) ($auth['role'] ?? '');
        if ($role === 'vendor' && (string) ($auth['vendorId'] ?? '') !== $vendorId) {
            throw new ApiException('Forbidden', 403);
        }

        $doc = AppContext::boot()->mongo->collection('vendors')->findOne(['listingId' => $vendorId]);
        if ($doc === null) {
            throw new ApiException('Vendor not found', 404);
        }

        return JsonResponse::ok(['vendor' => $this->serializeVendor((array) $doc)]);
    }

    public function register(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);

        $state = trim((string) ($body['state'] ?? ''));
        $district = trim((string) ($body['district'] ?? ''));
        if ($state === '' || $district === '') {
            throw new ApiException('State and district are required', 422);
        }

        $listingId = Ids::new('vn');
        $primaryLocation = trim((string) ($body['primaryLocation'] ?? $body['city'] ?? ''));
        if ($primaryLocation === '') {
            $primaryLocation = $district . ', ' . $state;
        }

        $contactEmail = strtolower(trim((string) ($body['email'] ?? '')));
        $contactPhone = trim((string) ($body['phone'] ?? ''));

        $doc = [
            'listingId' => $listingId,
            'name' => (string) ($body['businessName'] ?? $body['name'] ?? 'New Vendor'),
            'location' => $primaryLocation,
            'businessAddress' => '',
            'addressLine1' => '',
            'addressLine2' => '',
            'landmark' => '',
            'pinCode' => '',
            'state' => $state,
            'district' => $district,
            'city' => $district,
            'villagesServed' => [],
            'contactName' => (string) ($body['contactName'] ?? ''),
            'description' => (string) ($body['description'] ?? ''),
            'rating' => 0,
            'price' => (string) ($body['price'] ?? 'On request'),
            'category' => (string) ($body['category'] ?? 'venues'),
            'image' => (string) ($body['image'] ?? ''),
            'contactEmail' => $contactEmail,
            'contactPhone' => $contactPhone,
            'services' => [],
            'status' => 'pending_review',
            'createdAt' => new UTCDateTime(),
        ];

        AppContext::boot()->mongo->collection('vendors')->insertOne($doc);

        // Create the vendor login account (users collection) with default password.
        // NOTE: Default password must be provided via env var; do not hardcode.
        $defaultPassword = Env::string('VENDOR_DEFAULT_PASSWORD', '');
        if ($defaultPassword === '') {
            throw new ApiException('Vendor default password is not configured', 500);
        }
        AppContext::boot()->auth->assertPasswordStrength($defaultPassword);

        $users = AppContext::boot()->mongo->collection('users');
        $existing = $users->findOne([
            'role' => 'vendor',
            '$or' => [
                ['phone' => AppContext::boot()->auth->normalizePhone($contactPhone)],
                ['email' => $contactEmail],
            ],
        ]);
        if ($existing === null) {
            $users->insertOne([
                '_id' => new \MongoDB\BSON\ObjectId(),
                'role' => 'vendor',
                'vendorId' => $listingId,
                'phone' => AppContext::boot()->auth->normalizePhone($contactPhone),
                'email' => $contactEmail,
                'contactName' => (string) ($body['contactName'] ?? 'Vendor Contact'),
                'businessName' => (string) ($body['businessName'] ?? $body['name'] ?? 'My Business'),
                'passwordHash' => AppContext::boot()->auth->hashPassword($defaultPassword),
                'createdAt' => new UTCDateTime(),
                'updatedAt' => new UTCDateTime(),
            ]);
        }

        return JsonResponse::ok(['vendor' => $this->serializeVendor($doc)], 201);
    }

    public function changePassword(ServerRequestInterface $request): ResponseInterface
    {
        $vendorId = (string) $request->getAttribute('id');
        $auth = (array) $request->getAttribute('auth');
        if (($auth['role'] ?? '') !== 'vendor' || (string) ($auth['vendorId'] ?? '') !== $vendorId) {
            throw new ApiException('Forbidden', 403);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $currentPassword = (string) ($body['currentPassword'] ?? '');
        $newPassword = (string) ($body['newPassword'] ?? '');
        $confirmPassword = (string) ($body['confirmPassword'] ?? $newPassword);

        if ($currentPassword === '' || $newPassword === '') {
            throw new ApiException('Current password and new password are required', 422);
        }
        if ($newPassword !== $confirmPassword) {
            throw new ApiException('Passwords do not match', 422);
        }
        AppContext::boot()->auth->assertPasswordStrength($newPassword);

        $userId = (string) $request->getAttribute('userId');
        $users = AppContext::boot()->mongo->collection('users');
        $user = $users->findOne(['_id' => new \MongoDB\BSON\ObjectId($userId), 'role' => 'vendor']);
        if ($user === null) {
            throw new ApiException('User not found', 404);
        }

        $hash = (string) ($user['passwordHash'] ?? '');
        if ($hash === '' || !password_verify($currentPassword, $hash)) {
            throw new ApiException('Invalid current password', 401);
        }

        $users->updateOne(
            ['_id' => $user['_id']],
            ['$set' => ['passwordHash' => AppContext::boot()->auth->hashPassword($newPassword), 'updatedAt' => new UTCDateTime()]]
        );

        return JsonResponse::ok(['changed' => true]);
    }

    public function updateProfile(ServerRequestInterface $request): ResponseInterface
    {
        $vendorId = (string) $request->getAttribute('id');
        $auth = (array) $request->getAttribute('auth');
        if (($auth['vendorId'] ?? null) !== null && $auth['vendorId'] !== $vendorId && ($auth['role'] ?? '') !== 'admin') {
            throw new ApiException('Forbidden', 403);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $addressLine1 = trim((string) ($body['addressLine1'] ?? ''));
        $pinCode = trim((string) ($body['pinCode'] ?? ''));
        $state = trim((string) ($body['state'] ?? ''));
        $district = trim((string) ($body['district'] ?? ''));

        if ($addressLine1 === '' || $pinCode === '' || $state === '' || $district === '') {
            throw new ApiException('Street address, PIN code, state, and district are required', 422);
        }
        if (!preg_match('/^\d{6}$/', $pinCode)) {
            throw new ApiException('Enter a valid 6-digit PIN code', 422);
        }

        $addressLine2 = trim((string) ($body['addressLine2'] ?? ''));
        $landmark = trim((string) ($body['landmark'] ?? ''));
        $parts = array_filter([
            $addressLine1,
            $addressLine2,
            $landmark,
            'PIN ' . $pinCode,
        ]);
        $businessAddress = implode(', ', $parts);
        $primaryLocation = trim((string) ($body['primaryLocation'] ?? ''));
        if ($primaryLocation === '') {
            $primaryLocation = $district . ', ' . $state;
        }
        $location = $businessAddress . ', ' . $primaryLocation;
        $villagesServed = $this->normalizeStringList($body['villagesServed'] ?? []);

        $update = [
            'addressLine1' => $addressLine1,
            'addressLine2' => $addressLine2,
            'landmark' => $landmark,
            'pinCode' => $pinCode,
            'state' => $state,
            'district' => $district,
            'city' => $district,
            'businessAddress' => $businessAddress,
            'location' => $location,
            'villagesServed' => $villagesServed,
            'updatedAt' => new UTCDateTime(),
        ];

        if (isset($body['image']) && trim((string) $body['image']) !== '') {
            $update['image'] = trim((string) $body['image']);
        }

        $result = AppContext::boot()->mongo->collection('vendors')->updateOne(
            ['listingId' => $vendorId],
            ['$set' => $update]
        );

        if ($result->getMatchedCount() === 0) {
            throw new ApiException('Vendor not found', 404);
        }

        $doc = AppContext::boot()->mongo->collection('vendors')->findOne(['listingId' => $vendorId]);

        return JsonResponse::ok(['vendor' => $this->serializeVendor((array) $doc)]);
    }

    public function addService(ServerRequestInterface $request): ResponseInterface
    {
        $vendorId = (string) $request->getAttribute('id');
        $auth = (array) $request->getAttribute('auth');
        if (($auth['vendorId'] ?? null) !== null && $auth['vendorId'] !== $vendorId && ($auth['role'] ?? '') !== 'admin') {
            throw new ApiException('Forbidden', 403);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $name = trim((string) ($body['name'] ?? ''));
        $description = trim((string) ($body['description'] ?? ''));
        $category = trim((string) ($body['category'] ?? ''));
        $image = trim((string) ($body['image'] ?? ''));
        $price = (float) ($body['price'] ?? 0);
        if ($name === '' || $description === '' || $category === '') {
            throw new ApiException('Service name, category, and description are required', 422);
        }
        if ($price <= 0) {
            throw new ApiException('Service price must be greater than zero', 422);
        }
        if ($image === '') {
            throw new ApiException('Service image is required', 422);
        }

        $service = [
            'id' => Ids::new('svc'),
            'name' => $name,
            'description' => $description,
            'category' => $category,
            'image' => $image,
            'price' => $price,
            'rating' => 0,
            'ratingCount' => 0,
            'createdAt' => new UTCDateTime(),
        ];

        $result = AppContext::boot()->mongo->collection('vendors')->updateOne(
            ['listingId' => $vendorId],
            ['$push' => ['services' => $service]]
        );

        if ($result->getMatchedCount() === 0) {
            throw new ApiException('Vendor not found', 404);
        }

        return JsonResponse::ok(['service' => BsonSerializer::normalize($service)], 201);
    }

    public function listEnquiries(ServerRequestInterface $request): ResponseInterface
    {
        $vendorId = (string) $request->getAttribute('id');
        $auth = (array) $request->getAttribute('auth');
        if (($auth['vendorId'] ?? null) !== null && $auth['vendorId'] !== $vendorId && ($auth['role'] ?? '') !== 'admin') {
            throw new ApiException('Forbidden', 403);
        }

        $items = [];
        $cursor = AppContext::boot()->mongo->collection('vendor_enquiries')->find(
            ['vendorId' => $vendorId],
            ['sort' => ['createdAt' => -1]]
        );
        foreach ($cursor as $doc) {
            $items[] = BsonSerializer::normalize((array) $doc);
        }

        return JsonResponse::ok(['enquiries' => $items]);
    }

    public function createEnquiry(ServerRequestInterface $request): ResponseInterface
    {
        $vendorId = (string) $request->getAttribute('id');
        $body = (array) ($request->getParsedBody() ?? []);

        $doc = [
            'id' => Ids::new('ENQ'),
            'vendorId' => $vendorId,
            'guestName' => (string) ($body['guestName'] ?? ''),
            'eventType' => (string) ($body['eventType'] ?? ''),
            'eventDate' => (string) ($body['eventDate'] ?? ''),
            'guests' => (string) ($body['guests'] ?? ''),
            'status' => 'New',
            'receivedAt' => gmdate('Y-m-d H:i:s'),
            'message' => (string) ($body['message'] ?? ''),
            'createdAt' => new UTCDateTime(),
        ];

        AppContext::boot()->mongo->collection('vendor_enquiries')->insertOne($doc);

        return JsonResponse::ok(['enquiry' => BsonSerializer::normalize($doc)], 201);
    }

    /** @return array<string, mixed> */
    private function publicVisibilityFilter(): array
    {
        return [
            '$or' => [
                ['status' => 'approved'],
                ['status' => ['$exists' => false]],
            ],
        ];
    }

    /** @param mixed $raw */
    private function normalizeStringList($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            $value = trim((string) $item);
            if ($value !== '') {
                $out[] = $value;
            }
        }

        return array_values(array_unique($out));
    }

    /** @param array<string, mixed> $doc */
    private function serializeVendor(array $doc): array
    {
        $normalized = BsonSerializer::normalize($doc);
        if (isset($normalized['listingId'])) {
            $normalized['id'] = $normalized['listingId'];
        }

        return $normalized;
    }
}
