<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\AppContext;
use App\Services\VendorCategoryService;
use App\Utils\ApiException;
use App\Utils\BsonSerializer;
use App\Utils\JsonResponse;
use MongoDB\BSON\UTCDateTime;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AdminVendorController
{
    public function listVendors(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $filter = [];

        $status = trim((string) ($params['status'] ?? ''));
        if ($status !== '') {
            $filter['status'] = $status;
        }

        if (!empty($params['category'])) {
            $filter['category'] = (string) $params['category'];
        }

        if (!empty($params['q'])) {
            $q = preg_quote((string) $params['q'], '/');
            $filter['$or'] = [
                ['name' => ['$regex' => $q, '$options' => 'i']],
                ['contactEmail' => ['$regex' => $q, '$options' => 'i']],
                ['contactPhone' => ['$regex' => $q, '$options' => 'i']],
                ['listingId' => ['$regex' => $q, '$options' => 'i']],
            ];
        }

        $items = [];
        $cursor = AppContext::boot()->mongo->collection('vendors')->find(
            $filter,
            ['sort' => ['createdAt' => -1]]
        );
        foreach ($cursor as $doc) {
            $items[] = $this->serializeAdminVendor((array) $doc);
        }

        return JsonResponse::ok(['vendors' => $items]);
    }

    public function updateVendorStatus(ServerRequestInterface $request): ResponseInterface
    {
        $vendorId = (string) $request->getAttribute('id');
        $body = (array) ($request->getParsedBody() ?? []);
        $status = trim((string) ($body['status'] ?? ''));

        $allowed = ['pending_review', 'approved', 'rejected'];
        if (!in_array($status, $allowed, true)) {
            throw new ApiException(
                'status must be one of: pending_review, approved, rejected',
                422
            );
        }

        $update = [
            'status' => $status,
            'updatedAt' => new UTCDateTime(),
        ];
        if ($status === 'approved') {
            $update['approvedAt'] = new UTCDateTime();
        }

        $result = AppContext::boot()->mongo->collection('vendors')->updateOne(
            ['listingId' => $vendorId],
            ['$set' => $update]
        );

        if ($result->getMatchedCount() === 0) {
            throw new ApiException('Vendor not found', 404);
        }

        $doc = AppContext::boot()->mongo->collection('vendors')->findOne(['listingId' => $vendorId]);

        return JsonResponse::ok(['vendor' => $this->serializeAdminVendor((array) $doc)]);
    }

    public function listVendorCategories(ServerRequestInterface $request): ResponseInterface
    {
        $categories = (new VendorCategoryService())->list();

        return JsonResponse::ok(['categories' => $categories]);
    }

    public function createVendorCategory(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $id = (string) ($body['id'] ?? '');
        $name = (string) ($body['name'] ?? '');

        if ($id === '') {
            throw new ApiException('Category id is required', 422);
        }

        $category = (new VendorCategoryService())->create($id, $name);

        return JsonResponse::ok(['category' => $category], 201);
    }

    public function updateVendorCategory(ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $request->getAttribute('id');
        $body = (array) ($request->getParsedBody() ?? []);
        $name = (string) ($body['name'] ?? '');

        $category = (new VendorCategoryService())->update($id, $name);

        return JsonResponse::ok(['category' => $category]);
    }

    public function deleteVendorCategory(ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $request->getAttribute('id');
        (new VendorCategoryService())->delete($id);

        return JsonResponse::ok(['deleted' => true]);
    }

    /** @param array<string, mixed> $doc */
    private function serializeAdminVendor(array $doc): array
    {
        $normalized = BsonSerializer::normalize($doc);
        if (isset($normalized['listingId'])) {
            $normalized['id'] = $normalized['listingId'];
        }
        if (!isset($normalized['status']) || $normalized['status'] === '') {
            $normalized['status'] = 'approved';
        }

        return $normalized;
    }
}
