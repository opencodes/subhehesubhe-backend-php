<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\VendorCategoryService;
use App\Utils\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Static catalog data aligned with utsav-connect frontend. */
final class CatalogController
{
    public function categories(ServerRequestInterface $request): ResponseInterface
    {
        return JsonResponse::ok([
            'categories' => [
                ['id' => 'sweets', 'name' => 'Festive Sweets', 'icon' => '✨', 'count' => 12],
                ['id' => 'biryani', 'name' => 'Royal Biryani', 'icon' => '🍛', 'count' => 8],
                ['id' => 'thali', 'name' => 'Shahi Thali', 'icon' => '🍱', 'count' => 5],
                ['id' => 'paneer', 'name' => 'Paneer Specials', 'icon' => '🧀', 'count' => 18],
                ['id' => 'chaat', 'name' => 'Desi Chaat', 'icon' => '🍢', 'count' => 14],
                ['id' => 'drinks', 'name' => 'Cool Elixirs', 'icon' => '🥤', 'count' => 9],
                ['id' => 'breads', 'name' => 'Tandoor Breads', 'icon' => '🫓', 'count' => 11],
            ],
        ]);
    }

    public function coupons(ServerRequestInterface $request): ResponseInterface
    {
        return JsonResponse::ok([
            'coupons' => [
                ['code' => 'DUSSEHRA50', 'discount' => '50% OFF', 'desc' => 'Up to ₹120 on festival special meals'],
                ['code' => 'FESTIVEFEAST', 'discount' => '₹100 FLAT', 'desc' => 'On orders of ₹499 and above'],
                ['code' => 'KASARIALOVE', 'discount' => 'FREE SWEET', 'desc' => 'Get free Kaju Katli box on orders above ₹599'],
                ['code' => 'WELCOMEUTSAV', 'discount' => '60% OFF', 'desc' => 'Welcome deal for first-time festival diners'],
            ],
        ]);
    }

    public function vendorCategories(ServerRequestInterface $request): ResponseInterface
    {
        $categories = (new VendorCategoryService())->list();

        return JsonResponse::ok(['categories' => $categories]);
    }
}
