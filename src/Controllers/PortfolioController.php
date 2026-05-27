<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\AppContext;
use App\Utils\BsonSerializer;
use App\Utils\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PortfolioController
{
    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $filter = [];
        if (!empty($params['category']) && $params['category'] !== 'All') {
            $filter['category'] = (string) $params['category'];
        }

        $items = [];
        $cursor = AppContext::boot()->mongo->collection('portfolio')->find(
            $filter,
            ['sort' => ['date' => -1]]
        );
        foreach ($cursor as $doc) {
            $items[] = BsonSerializer::normalize((array) $doc);
        }

        return JsonResponse::ok(['portfolio' => $items]);
    }
}
