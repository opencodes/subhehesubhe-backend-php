<?php

declare(strict_types=1);

namespace App\Routes;

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\CampaignController;
use App\Controllers\CatalogController;
use App\Controllers\HealthController;
use App\Controllers\OrderController;
use App\Controllers\PlannerController;
use App\Controllers\PortfolioController;
use App\Controllers\RestaurantController;
use App\Controllers\UserController;
use App\Controllers\VendorController;
use App\Middleware\AuthMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

final class ApiRoutes
{
    public static function register(App $app): void
    {
        $app->get('/health', [HealthController::class, 'index']);

        $app->group('/api/v1', function (RouteCollectorProxy $group) {
            $auth = new AuthController();
            $group->post('/auth/sign-in', [$auth, 'signInCustomer']);
            $group->post('/auth/vendor/sign-in', [$auth, 'signInVendor']);
            $group->post('/auth/register/planner', [$auth, 'registerPlanner']);

            $catalog = new CatalogController();
            $group->get('/catalog/categories', [$catalog, 'categories']);
            $group->get('/catalog/coupons', [$catalog, 'coupons']);
            $group->get('/catalog/vendor-categories', [$catalog, 'vendorCategories']);

            $restaurants = new RestaurantController();
            $group->get('/restaurants', [$restaurants, 'list']);
            $group->get('/restaurants/{id}', [$restaurants, 'get']);

            $vendors = new VendorController();
            $group->get('/vendors', [$vendors, 'list']);
            $group->get('/vendors/{id}', [$vendors, 'get']);
            $group->post('/vendors/register', [$vendors, 'register']);
            $group->post('/vendors/{id}/enquiries', [$vendors, 'createEnquiry']);

            $portfolio = new PortfolioController();
            $group->get('/portfolio', [$portfolio, 'list']);

            $campaigns = new CampaignController();
            $group->get('/campaigns', [$campaigns, 'list']);

            $group->group('', function (RouteCollectorProxy $secured) {
                $authMw = new AuthMiddleware();

                $auth = new AuthController();
                $secured->get('/auth/me', [$auth, 'me'])->add($authMw);

                $users = new UserController();
                $secured->get('/users/me', [$users, 'getProfile'])->add($authMw);
                $secured->patch('/users/me', [$users, 'updateProfile'])->add($authMw);
                $secured->post('/users/me/addresses', [$users, 'addAddress'])->add($authMw);

                $orders = new OrderController();
                $secured->post('/orders', [$orders, 'create'])->add($authMw);

                $planner = new PlannerController();
                $secured->get('/planner/workspace', [$planner, 'getWorkspace'])->add($authMw);
                $secured->put('/planner/workspace', [$planner, 'saveWorkspace'])->add($authMw);

                $vendors = new VendorController();
                $secured->get('/vendors/{id}/enquiries', [$vendors, 'listEnquiries'])->add($authMw);
            });

            $group->group('/admin', function (RouteCollectorProxy $admin) {
                $adminAuth = new AuthMiddleware(['admin', 'customer']);

                $adminCtrl = new AdminController();
                $admin->get('/stats', [$adminCtrl, 'stats'])->add($adminAuth);

                $users = new UserController();
                $admin->get('/customers', [$users, 'listCustomers'])->add($adminAuth);

                $orders = new OrderController();
                $admin->get('/orders', [$orders, 'list'])->add($adminAuth);
                $admin->get('/orders/{id}', [$orders, 'get'])->add($adminAuth);
                $admin->patch('/orders/{id}/status', [$orders, 'updateStatus'])->add($adminAuth);

                $restaurants = new RestaurantController();
                $admin->post('/restaurants', [$restaurants, 'create'])->add($adminAuth);
                $admin->put('/restaurants/{id}', [$restaurants, 'update'])->add($adminAuth);
                $admin->delete('/restaurants/{id}', [$restaurants, 'delete'])->add($adminAuth);

                $campaigns = new CampaignController();
                $admin->post('/campaigns', [$campaigns, 'create'])->add($adminAuth);
                $admin->patch('/campaigns/{id}', [$campaigns, 'update'])->add($adminAuth);
            });
        });
    }
}
