<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\AppContext;
use App\Utils\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class HealthController
{
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $mongoOk = false;
        try {
            AppContext::boot()->mongo->db()->command(['ping' => 1]);
            $mongoOk = true;
        } catch (\Throwable) {
            $mongoOk = false;
        }

        return JsonResponse::ok([
            'status' => 'ok',
            'service' => 'shubhesubhe-api',
            'mongodb' => $mongoOk ? 'connected' : 'unavailable',
            'time' => gmdate(DATE_ATOM),
        ]);
    }
}
