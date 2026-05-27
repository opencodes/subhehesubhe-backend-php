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

final class CampaignController
{
    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $items = [];
        $cursor = AppContext::boot()->mongo->collection('campaigns')->find(
            [],
            ['sort' => ['startDate' => -1]]
        );
        foreach ($cursor as $doc) {
            $items[] = $this->serialize((array) $doc);
        }

        return JsonResponse::ok(['campaigns' => $items]);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $body['campaignId'] = (string) ($body['id'] ?? Ids::new('camp'));
        unset($body['id']);

        AppContext::boot()->mongo->collection('campaigns')->insertOne($body);

        return JsonResponse::ok(['campaign' => $this->serialize($body)], 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $request->getAttribute('id');
        $body = (array) ($request->getParsedBody() ?? []);
        unset($body['campaignId'], $body['id']);

        $result = AppContext::boot()->mongo->collection('campaigns')->updateOne(
            ['campaignId' => $id],
            ['$set' => $body]
        );
        if ($result->getMatchedCount() === 0) {
            throw new ApiException('Campaign not found', 404);
        }

        $doc = AppContext::boot()->mongo->collection('campaigns')->findOne(['campaignId' => $id]);
        return JsonResponse::ok(['campaign' => $this->serialize((array) $doc)]);
    }

    /** @param array<string, mixed> $doc */
    private function serialize(array $doc): array
    {
        $normalized = BsonSerializer::normalize($doc);
        if (isset($normalized['campaignId'])) {
            $normalized['id'] = $normalized['campaignId'];
        }

        return $normalized;
    }
}
