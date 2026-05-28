<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\AppContext;
use App\Utils\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AdminController
{
    public function stats(ServerRequestInterface $request): ResponseInterface
    {
        $mongo = AppContext::boot()->mongo;
        $orders = $mongo->collection('orders');
        $users = $mongo->collection('users');
        $restaurants = $mongo->collection('restaurants');

        $vendors = $mongo->collection('vendors');
        $pendingVendors = $vendors->countDocuments(['status' => 'pending_review']);

        $totalOrders = $orders->countDocuments([]);
        $pipeline = [
            ['$group' => ['_id' => null, 'total' => ['$sum' => '$totalAmount']]],
        ];
        $revenueAgg = $orders->aggregate($pipeline)->toArray();
        $totalRevenue = (int) ($revenueAgg[0]['total'] ?? 0);

        $recent = [];
        $cursor = $orders->find([], ['sort' => ['createdAt' => -1], 'limit' => 10]);
        foreach ($cursor as $doc) {
            $o = (array) $doc;
            $recent[] = [
                'id' => $o['orderId'] ?? '',
                'customerName' => $o['customerName'] ?? 'Customer',
                'restaurantName' => $o['restaurantName'] ?? '',
                'items' => $o['itemsSummary'] ?? '',
                'amount' => (int) ($o['totalAmount'] ?? $o['amount'] ?? 0),
                'status' => $o['status'] ?? 'Pending',
                'time' => $o['time'] ?? '',
            ];
        }

        $categorySales = $this->categorySalesFromRestaurants();

        return JsonResponse::ok([
            'stats' => [
                'totalOrders' => $totalOrders,
                'totalRevenue' => $totalRevenue,
                'activeCustomers' => $users->countDocuments(['role' => 'customer']),
                'activeRestaurants' => $restaurants->countDocuments([]),
                'pendingVendors' => $pendingVendors,
                'revenueTrend' => $this->revenueTrend($orders),
                'categorySales' => $categorySales,
                'recentOrders' => $recent,
            ],
        ]);
    }

    private function revenueTrend(\MongoDB\Collection $orders): array
    {
        $pipeline = [
            [
                '$group' => [
                    '_id' => ['$dateToString' => ['format' => '%d %b', 'date' => '$createdAt']],
                    'revenue' => ['$sum' => '$totalAmount'],
                    'orders' => ['$sum' => 1],
                ],
            ],
            ['$sort' => ['_id' => 1]],
            ['$limit' => 14],
        ];

        $trend = [];
        foreach ($orders->aggregate($pipeline) as $row) {
            $r = (array) $row;
            $trend[] = [
                'date' => (string) ($r['_id'] ?? ''),
                'revenue' => (int) ($r['revenue'] ?? 0),
                'orders' => (int) ($r['orders'] ?? 0),
            ];
        }

        return $trend;
    }

    /** @return list<array{category: string, value: int}> */
    private function categorySalesFromRestaurants(): array
    {
        $counts = [];
        $cursor = AppContext::boot()->mongo->collection('restaurants')->find([]);
        foreach ($cursor as $doc) {
            foreach ((array) ($doc['menu'] ?? []) as $item) {
                $cat = (string) (($item['category'] ?? 'Other'));
                $counts[$cat] = ($counts[$cat] ?? 0) + 1;
            }
        }

        arsort($counts);
        $total = array_sum($counts) ?: 1;
        $sales = [];
        foreach ($counts as $category => $count) {
            $sales[] = [
                'category' => $category,
                'value' => (int) round(($count / $total) * 100),
            ];
        }

        return array_slice($sales, 0, 8);
    }
}
