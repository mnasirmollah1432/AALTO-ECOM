<?php

/**
 * Route definitions. $router is provided by public/index.php.
 *
 * @var App\Core\Router $router
 */

use App\Controllers\Admin;
use App\Controllers\CartController;
use App\Controllers\CheckoutController;
use App\Controllers\HomeController;
use App\Controllers\PageController;
use App\Controllers\ProductController;
use App\Controllers\SeoController;
use App\Controllers\ShopController;
use App\Controllers\WishlistController;

// Storefront
$router->get('/', [HomeController::class, 'index']);
$router->get('/shop', [ShopController::class, 'index']);
$router->get('/category/{slug}', [ShopController::class, 'category']);
$router->get('/product/{slug}', [ProductController::class, 'show']);
$router->get('/cart', [CartController::class, 'show']);
$router->post('/cart/add', [CartController::class, 'add']);
$router->post('/cart/update', [CartController::class, 'update']);
$router->post('/cart/remove', [CartController::class, 'remove']);
$router->post('/cart/clear', [CartController::class, 'clear']);
$router->get('/checkout', [CheckoutController::class, 'show']);
$router->post('/checkout', [CheckoutController::class, 'place']);
$router->get('/order-success', [CheckoutController::class, 'success']);
$router->get('/wishlist', [WishlistController::class, 'show']);
$router->post('/wishlist/toggle', [WishlistController::class, 'toggle']);
$router->get('/contact', [PageController::class, 'contact']);
$router->get('/robots.txt', [SeoController::class, 'robots']);
$router->get('/sitemap.xml', [SeoController::class, 'sitemap']);

// Placeholders for pages built in later phases
$router->get('/about', [PageController::class, 'comingSoon']);

// Admin: authentication
$router->get('/admin', [Admin\AuthController::class, 'index']);
$router->get('/admin/login', [Admin\AuthController::class, 'showLogin']);
$router->post('/admin/login', [Admin\AuthController::class, 'login']);
$router->get('/admin/logout', [Admin\AuthController::class, 'confirmLogout']);
$router->post('/admin/logout', [Admin\AuthController::class, 'logout']);

// Admin: protected pages (every handler calls require_admin())
$router->get('/admin/dashboard', [Admin\DashboardController::class, 'index']);

// Admin: products
$router->get('/admin/products', [Admin\ProductController::class, 'index']);
$router->get('/admin/products/create', [Admin\ProductController::class, 'create']);
$router->post('/admin/products/create', [Admin\ProductController::class, 'store']);
$router->get('/admin/products/edit', static function (): never {
    require_admin();
    redirect('admin/products'); // edit needs an id: /admin/products/edit/{id}
});
$router->get('/admin/products/edit/{id}', [Admin\ProductController::class, 'edit']);
$router->post('/admin/products/edit/{id}', [Admin\ProductController::class, 'update']);
$router->post('/admin/products/toggle/{id}', [Admin\ProductController::class, 'toggle']);
$router->post('/admin/products/delete/{id}', [Admin\ProductController::class, 'destroy']);

// Admin: categories
$router->get('/admin/categories', [Admin\CategoryController::class, 'index']);
$router->get('/admin/categories/create', [Admin\CategoryController::class, 'create']);
$router->post('/admin/categories/create', [Admin\CategoryController::class, 'store']);
$router->get('/admin/categories/edit', static function (): never {
    require_admin();
    redirect('admin/categories'); // edit needs an id: /admin/categories/edit/{id}
});
$router->get('/admin/categories/edit/{id}', [Admin\CategoryController::class, 'edit']);
$router->post('/admin/categories/edit/{id}', [Admin\CategoryController::class, 'update']);
$router->post('/admin/categories/toggle/{id}', [Admin\CategoryController::class, 'toggle']);
$router->post('/admin/categories/delete/{id}', [Admin\CategoryController::class, 'destroy']);

// Admin: orders
$router->get('/admin/orders', [Admin\OrderController::class, 'index']);
$router->get('/admin/orders/view', static function (): never {
    require_admin();
    redirect('admin/orders'); // view needs an id: /admin/orders/view/{id}
});
$router->get('/admin/orders/view/{id}', [Admin\OrderController::class, 'view']);
$router->post('/admin/orders/status/{id}', [Admin\OrderController::class, 'updateStatus']);
$router->post('/admin/orders/courier-bulk', [Admin\CourierController::class, 'bulk']);
$router->post('/admin/orders/courier/{id}', [Admin\CourierController::class, 'order']);

// Admin: delivery (zones and charges, free delivery, districts, thanas)
$router->get('/admin/delivery', [Admin\DeliveryController::class, 'index']);
$router->post('/admin/delivery/zones', [Admin\DeliveryController::class, 'saveZones']);
$router->post('/admin/delivery/zones/delete/{id}', [Admin\DeliveryController::class, 'deleteZone']);
$router->post('/admin/delivery/settings', [Admin\DeliveryController::class, 'saveSettings']);
$router->post('/admin/delivery/districts/create', [Admin\DeliveryController::class, 'createDistrict']);
$router->post('/admin/delivery/districts/delete/{id}', [Admin\DeliveryController::class, 'deleteDistrict']);
$router->get('/admin/delivery/districts/{id}', [Admin\DeliveryController::class, 'district']);
$router->post('/admin/delivery/districts/{id}', [Admin\DeliveryController::class, 'updateDistrict']);
$router->post('/admin/delivery/districts/{id}/thanas', [Admin\DeliveryController::class, 'createThanas']);
$router->post('/admin/delivery/thanas/{id}', [Admin\DeliveryController::class, 'updateThana']);

// Admin: home slider
$router->get('/admin/slides', [Admin\SlideController::class, 'index']);
$router->get('/admin/slides/create', [Admin\SlideController::class, 'create']);
$router->post('/admin/slides/create', [Admin\SlideController::class, 'store']);
$router->get('/admin/slides/edit/{id}', [Admin\SlideController::class, 'edit']);
$router->post('/admin/slides/edit/{id}', [Admin\SlideController::class, 'update']);
$router->post('/admin/slides/move/{id}', [Admin\SlideController::class, 'move']);
$router->post('/admin/slides/toggle/{id}', [Admin\SlideController::class, 'toggle']);
$router->post('/admin/slides/delete/{id}', [Admin\SlideController::class, 'destroy']);

// Admin: contact details, social links, chat button
$router->get('/admin/contact', [Admin\ContactController::class, 'edit']);
$router->post('/admin/contact', [Admin\ContactController::class, 'update']);

// Admin: appearance (announcement bar, menu line)
$router->get('/admin/appearance', [Admin\AppearanceController::class, 'edit']);
$router->post('/admin/appearance', [Admin\AppearanceController::class, 'update']);

// Admin: courier (Steadfast) and marketing tracking
$router->get('/admin/courier', [Admin\CourierController::class, 'edit']);
$router->post('/admin/courier', [Admin\CourierController::class, 'update']);
$router->post('/admin/courier/test', [Admin\CourierController::class, 'test']);
$router->get('/admin/tracking', [Admin\TrackingController::class, 'edit']);
$router->post('/admin/tracking', [Admin\TrackingController::class, 'update']);

// Webhooks (no CSRF: authenticated by token)
$router->post('/webhooks/steadfast', [App\Controllers\WebhookController::class, 'steadfast']);

// Lightweight health check (no sensitive details)
$router->get('/health', static function (): void {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    try {
        db_value('SELECT 1');
        echo json_encode(['status' => 'ok', 'database' => 'connected']);
    } catch (Throwable) {
        http_response_code(503);
        echo json_encode(['status' => 'error', 'database' => 'unavailable']);
    }
});
