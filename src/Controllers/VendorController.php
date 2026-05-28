<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\AppContext;
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
            'contactEmail' => (string) ($body['email'] ?? ''),
            'contactPhone' => (string) ($body['phone'] ?? ''),
            'services' => [],
            'status' => 'pending_review',
            'createdAt' => new UTCDateTime(),
        ];

        AppContext::boot()->mongo->collection('vendors')->insertOne($doc);

        return JsonResponse::ok(['vendor' => $this->serializeVendor($doc)], 201);
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
