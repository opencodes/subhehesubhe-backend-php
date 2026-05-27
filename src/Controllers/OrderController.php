<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\AppContext;
use App\Utils\ApiException;
use App\Utils\BsonSerializer;
use App\Utils\Ids;
use App\Utils\JsonResponse;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class OrderController
{
    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $filter = [];
        if (!empty($params['status'])) {
            $filter['status'] = (string) $params['status'];
        }

        $items = [];
        $cursor = AppContext::boot()->mongo->collection('orders')->find(
            $filter,
            ['sort' => ['createdAt' => -1], 'limit' => 200]
        );
        foreach ($cursor as $doc) {
            $items[] = BsonSerializer::normalize((array) $doc);
        }

        return JsonResponse::ok(['orders' => $items]);
    }

    public function get(ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $request->getAttribute('id');
        $doc = AppContext::boot()->mongo->collection('orders')->findOne(['orderId' => $id]);
        if ($doc === null) {
            throw new ApiException('Order not found', 404);
        }

        return JsonResponse::ok(['order' => BsonSerializer::normalize((array) $doc)]);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $userId = (string) $request->getAttribute('userId');
        $body = (array) ($request->getParsedBody() ?? []);

        $items = $body['items'] ?? [];
        if (!is_array($items) || $items === []) {
            throw new ApiException('Order items are required', 422);
        }

        $total = (int) ($body['totalAmount'] ?? 0);
        if ($total <= 0) {
            foreach ($items as $item) {
                $total += (int) (($item['price'] ?? 0) * ($item['quantity'] ?? 1));
            }
        }

        $orderId = Ids::new('FED');
        $order = [
            'orderId' => $orderId,
            'userId' => $userId,
            'customerName' => (string) ($body['customerName'] ?? ''),
            'restaurantId' => (string) ($body['restaurantId'] ?? ''),
            'restaurantName' => (string) ($body['restaurantName'] ?? ''),
            'restaurantImage' => (string) ($body['restaurantImage'] ?? ''),
            'items' => $items,
            'itemsSummary' => (string) ($body['itemsSummary'] ?? $this->summarizeItems($items)),
            'amount' => $total,
            'totalAmount' => $total,
            'status' => 'Pending',
            'time' => 'Just now',
            'date' => gmdate('Y-m-d H:i'),
            'createdAt' => new UTCDateTime(),
        ];

        AppContext::boot()->mongo->collection('orders')->insertOne($order);

        $this->appendOrderToUser($userId, $order);

        return JsonResponse::ok(['order' => BsonSerializer::normalize($order)], 201);
    }

    public function updateStatus(ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $request->getAttribute('id');
        $body = (array) ($request->getParsedBody() ?? []);
        $status = (string) ($body['status'] ?? '');
        $allowed = ['Pending', 'Preparing', 'Out for Delivery', 'Delivered', 'Cancelled'];
        if (!in_array($status, $allowed, true)) {
            throw new ApiException('Invalid status', 422);
        }

        $result = AppContext::boot()->mongo->collection('orders')->updateOne(
            ['orderId' => $id],
            ['$set' => ['status' => $status, 'updatedAt' => new UTCDateTime()]]
        );
        if ($result->getMatchedCount() === 0) {
            throw new ApiException('Order not found', 404);
        }

        $doc = AppContext::boot()->mongo->collection('orders')->findOne(['orderId' => $id]);
        return JsonResponse::ok(['order' => BsonSerializer::normalize((array) $doc)]);
    }

    /** @param list<array<string, mixed>> $items */
    private function summarizeItems(array $items): string
    {
        $parts = [];
        foreach ($items as $item) {
            $parts[] = sprintf(
                '%s x%d',
                (string) ($item['name'] ?? 'Item'),
                (int) ($item['quantity'] ?? 1)
            );
        }

        return implode(', ', $parts);
    }

    /** @param array<string, mixed> $order */
    private function appendOrderToUser(string $userId, array $order): void
    {
        try {
            $users = AppContext::boot()->mongo->collection('users');
            $historyItem = [
                'id' => $order['orderId'],
                'restaurantName' => $order['restaurantName'],
                'restaurantImage' => $order['restaurantImage'],
                'date' => $order['date'],
                'status' => $order['status'],
                'items' => $order['items'],
                'totalAmount' => $order['totalAmount'],
            ];
            $users->updateOne(
                ['_id' => new ObjectId($userId)],
                ['$push' => ['orders' => $historyItem]]
            );
        } catch (\Throwable) {
            // non-fatal for checkout
        }
    }
}
