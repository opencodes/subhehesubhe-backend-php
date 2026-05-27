<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\AppContext;
use App\Utils\ApiException;
use App\Utils\BsonSerializer;
use App\Utils\Ids;
use App\Utils\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RestaurantController
{
    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $filter = [];

        if (!empty($params['q'])) {
            $q = preg_quote((string) $params['q'], '/');
            $filter['name'] = ['$regex' => $q, '$options' => 'i'];
        }
        if (!empty($params['cuisine'])) {
            $filter['cuisine'] = (string) $params['cuisine'];
        }
        if (isset($params['isVeg'])) {
            $filter['isVeg'] = filter_var($params['isVeg'], FILTER_VALIDATE_BOOLEAN);
        }

        $items = [];
        $cursor = AppContext::boot()->mongo->collection('restaurants')->find(
            $filter,
            ['sort' => ['rating' => -1]]
        );
        foreach ($cursor as $doc) {
            $items[] = $this->serializeRestaurant((array) $doc);
        }

        return JsonResponse::ok(['restaurants' => $items]);
    }

    public function get(ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $request->getAttribute('id');
        $doc = AppContext::boot()->mongo->collection('restaurants')->findOne(['listingId' => $id]);
        if ($doc === null) {
            throw new ApiException('Restaurant not found', 404);
        }

        return JsonResponse::ok(['restaurant' => $this->serializeRestaurant((array) $doc)]);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $listingId = (string) ($body['id'] ?? Ids::new('rest'));
        $body['listingId'] = $listingId;
        unset($body['id']);

        AppContext::boot()->mongo->collection('restaurants')->insertOne($body);

        return JsonResponse::ok(['restaurant' => $this->serializeRestaurant($body)], 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $request->getAttribute('id');
        $body = (array) ($request->getParsedBody() ?? []);
        unset($body['listingId'], $body['id']);

        $result = AppContext::boot()->mongo->collection('restaurants')->updateOne(
            ['listingId' => $id],
            ['$set' => $body]
        );
        if ($result->getMatchedCount() === 0) {
            throw new ApiException('Restaurant not found', 404);
        }

        $doc = AppContext::boot()->mongo->collection('restaurants')->findOne(['listingId' => $id]);
        return JsonResponse::ok(['restaurant' => $this->serializeRestaurant((array) $doc)]);
    }

    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $request->getAttribute('id');
        $result = AppContext::boot()->mongo->collection('restaurants')->deleteOne(['listingId' => $id]);
        if ($result->getDeletedCount() === 0) {
            throw new ApiException('Restaurant not found', 404);
        }

        return JsonResponse::message('Restaurant deleted');
    }

    /** @param array<string, mixed> $doc */
    private function serializeRestaurant(array $doc): array
    {
        $normalized = BsonSerializer::normalize($doc);
        if (isset($normalized['listingId'])) {
            $normalized['id'] = $normalized['listingId'];
        }

        return $normalized;
    }
}
