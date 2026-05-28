<?php

declare(strict_types=1);

namespace App\Routes;

use App\Controllers\AdminController;
use App\Controllers\AdminVendorController;
use App\Controllers\PlatformAuthController;
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

            $platformAuth = new PlatformAuthController();
            $group->post('/auth/platform/sign-in', [$platformAuth, 'signIn']);

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
                $secured->patch('/vendors/{id}', [$vendors, 'updateProfile'])->add($authMw);
                $secured->post('/vendors/{id}/services', [$vendors, 'addService'])->add($authMw);
            });

            $group->group('/root', function (RouteCollectorProxy $root) {
                $rootAuth = new AuthMiddleware(['root']);
                $platformAuth = new PlatformAuthController();
                $root->get('/admins', [$platformAuth, 'listAdmins'])->add($rootAuth);
                $root->post('/admins', [$platformAuth, 'createAdmin'])->add($rootAuth);
                $root->patch('/admins/{id}', [$platformAuth, 'updateAdmin'])->add($rootAuth);
            });

            $group->group('/admin', function (RouteCollectorProxy $admin) {
                $adminAuth = new AuthMiddleware(['admin', 'root']);
                $adminOnly = new AuthMiddleware(['admin', 'root']);

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

                $adminVendors = new AdminVendorController();
                $admin->get('/vendors', [$adminVendors, 'listVendors'])->add($adminOnly);
                $admin->patch('/vendors/{id}/status', [$adminVendors, 'updateVendorStatus'])->add($adminOnly);
                $admin->get('/vendor-categories', [$adminVendors, 'listVendorCategories'])->add($adminOnly);
                $admin->post('/vendor-categories', [$adminVendors, 'createVendorCategory'])->add($adminOnly);
                $admin->put('/vendor-categories/{id}', [$adminVendors, 'updateVendorCategory'])->add($adminOnly);
                $admin->delete('/vendor-categories/{id}', [$adminVendors, 'deleteVendorCategory'])->add($adminOnly);
            });
        });
    }
}
