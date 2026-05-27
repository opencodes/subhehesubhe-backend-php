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
        $filter = [];

        if (!empty($params['category'])) {
            $filter['category'] = (string) $params['category'];
        }
        if (!empty($params['city'])) {
            $city = preg_quote((string) $params['city'], '/');
            $filter['location'] = ['$regex' => $city, '$options' => 'i'];
        }
        if (!empty($params['q'])) {
            $q = preg_quote((string) $params['q'], '/');
            $filter['$or'] = [
                ['name' => ['$regex' => $q, '$options' => 'i']],
                ['location' => ['$regex' => $q, '$options' => 'i']],
            ];
        }

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
        $doc = AppContext::boot()->mongo->collection('vendors')->findOne(['listingId' => $id]);
        if ($doc === null) {
            throw new ApiException('Vendor not found', 404);
        }

        return JsonResponse::ok(['vendor' => $this->serializeVendor((array) $doc)]);
    }

    public function register(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $listingId = Ids::new('vn');
        $doc = [
            'listingId' => $listingId,
            'name' => (string) ($body['businessName'] ?? $body['name'] ?? 'New Vendor'),
            'location' => (string) ($body['location'] ?? $body['city'] ?? ''),
            'rating' => 0,
            'price' => (string) ($body['price'] ?? 'On request'),
            'category' => (string) ($body['category'] ?? 'venues'),
            'image' => (string) ($body['image'] ?? ''),
            'contactEmail' => (string) ($body['email'] ?? ''),
            'contactPhone' => (string) ($body['phone'] ?? ''),
            'status' => 'pending_review',
            'createdAt' => new UTCDateTime(),
        ];

        AppContext::boot()->mongo->collection('vendors')->insertOne($doc);

        return JsonResponse::ok(['vendor' => $this->serializeVendor($doc)], 201);
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
