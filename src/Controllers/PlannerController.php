<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\AppContext;
use App\Utils\ApiException;
use App\Utils\BsonSerializer;
use App\Utils\JsonResponse;
use MongoDB\BSON\UTCDateTime;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PlannerController
{
    public function getWorkspace(ServerRequestInterface $request): ResponseInterface
    {
        $userId = (string) $request->getAttribute('userId');
        $doc = $this->getOrCreateWorkspace($userId);

        return JsonResponse::ok(['workspace' => $this->serializeWorkspace((array) $doc)]);
    }

    public function saveWorkspace(ServerRequestInterface $request): ResponseInterface
    {
        $userId = (string) $request->getAttribute('userId');
        $body = (array) ($request->getParsedBody() ?? []);

        $allowedKeys = [
            'events', 'subEvents', 'rituals', 'guests', 'vendors', 'feast',
            'misc', 'bartan', 'cylinders', 'expenses', 'chuman',
            'budgetLimit', 'estVillagers', 'estRelatives',
        ];

        $update = ['updatedAt' => new UTCDateTime()];
        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $body)) {
                $update[$key] = $body[$key];
            }
        }

        AppContext::boot()->mongo->collection('planner_workspaces')->updateOne(
            ['userId' => $userId],
            ['$set' => $update],
            ['upsert' => true]
        );

        $doc = $this->getOrCreateWorkspace($userId);
        return JsonResponse::ok(['workspace' => $this->serializeWorkspace((array) $doc)]);
    }

    /** @return array<string, mixed> */
    private function getOrCreateWorkspace(string $userId): array
    {
        $col = AppContext::boot()->mongo->collection('planner_workspaces');
        $doc = $col->findOne(['userId' => $userId]);
        if ($doc !== null) {
            return (array) $doc;
        }

        $empty = [
            'userId' => $userId,
            'events' => [],
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
        ];
        $col->insertOne($empty);

        return $empty;
    }

    /** @param array<string, mixed> $doc */
    private function serializeWorkspace(array $doc): array
    {
        $normalized = BsonSerializer::normalize($doc);
        unset($normalized['_id']);

        return $normalized;
    }
}
