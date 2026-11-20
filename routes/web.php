<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\AdminSessionController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminCategoryController;
use App\Http\Controllers\ProductPageController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\AdminProductController;
use App\Http\Controllers\AdminProductEditorController;
use App\Http\Controllers\AdminOrderController;
use App\Http\Controllers\AdminStockController;
use App\Http\Controllers\AdminPromotionController;
use App\Http\Controllers\AdminCouponController;
use App\Http\Controllers\AdminCustomerController;
use App\Http\Controllers\AdminWhatsAppController;
use App\Http\Controllers\AdminActivityController;
use App\Http\Controllers\PromotionsPageController;
use App\Http\Controllers\AdminOrderStatusController;
use App\Http\Controllers\AdminTodayController;
use App\Http\Controllers\AdminUserController;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\RequirePagePermission;
use App\Support\SpaRoutes;

// The pages the React app renders (admin and storefront) are listed once, in routes/spa.json (also read by resources/js/routes.js).
Route::get(SpaRoutes::adminLogin()['path'], fn (Request $request) => $request->user()?->is_active ? redirect($request->user()->role->home()) : view('app', ['seo' => SpaRoutes::adminSeo(SpaRoutes::adminLogin())]));
Route::post('/admin/login', [AdminSessionController::class, 'store']);

// "Mis entregas" no longer exists: the old address leads to Hoy (permanent redirect).
Route::redirect('/admin/deliveries', '/admin/today', 301);

// Every admin route sits behind EnsureStaff (signed in, active, current session) and then a permission:
// pages use `permission` of routes/spa.json (RequirePagePermission, answers the shell with 403), endpoints use `can:<permission>`.
// The permission matrix lives in App\Support\Permissions; docs: CLAUDE.md "Users and roles".
Route::middleware(EnsureStaff::class)->group(function () {
    foreach (SpaRoutes::adminPages() as $page) {
        $route = Route::view($page['path'], 'app', ['seo' => SpaRoutes::adminSeo($page)]);
        if (! empty($page['permission'])) {
            $route->middleware(RequirePagePermission::class.':'.$page['permission']);
        }
        if (preg_match_all('/\{(\w+)\}/', $page['path'], $parameters)) {
            $route->whereNumber($parameters[1]);
        }
    }

    // Any signed-in person
    Route::get('/admin/session', [AdminSessionController::class, 'show']);
    Route::post('/admin/logout', [AdminSessionController::class, 'destroy']);
    Route::post('/admin/password', [AdminSessionController::class, 'changePassword']);

    // today.view: what has to move now (each role gets its own slice)
    Route::get('/admin/today/data', AdminTodayController::class)->middleware('can:today.view');

    // Orders: orders.list opens Pedidos; each role gets its own slice (see AdminOrderController). Status steps are shared by Hoy and Pedidos.
    // There is NO route to confirm an order or mark it as paid: that is PaymentService, called by the payment webhook.
    Route::get('/admin/orders/data', [AdminOrderController::class, 'index'])->middleware('can:orders.list');
    Route::get('/admin/orders/{order}/detail', [AdminOrderController::class, 'show'])->whereNumber('order')->middleware('can:orders.list');
    Route::patch('/admin/orders/{order}/prepare', [AdminOrderStatusController::class, 'prepare'])->whereNumber('order')->middleware('can:orders.prepare');
    Route::patch('/admin/orders/{order}/ready', [AdminOrderStatusController::class, 'ready'])->whereNumber('order')->middleware('can:orders.prepare');
    Route::patch('/admin/orders/{order}/ship', [AdminOrderStatusController::class, 'ship'])->whereNumber('order')->middleware('can:orders.ship');
    Route::patch('/admin/orders/{order}/reassign', [AdminOrderStatusController::class, 'reassign'])->whereNumber('order')->middleware('can:orders.ship');
    Route::patch('/admin/orders/{order}/deliver', [AdminOrderStatusController::class, 'deliver'])->whereNumber('order')->middleware('can:orders.deliver');

    // dashboard.view
    Route::get('/admin/dashboard/summary', AdminDashboardController::class)->middleware('can:dashboard.view');


    Route::middleware('can:promotions.manage')->group(function () {
        Route::get('/admin/promotions/data', [AdminPromotionController::class, 'index']);
        Route::get('/admin/promotions/products', [AdminPromotionController::class, 'products']);
        Route::post('/admin/promotions', [AdminPromotionController::class, 'store']);
        Route::get('/admin/promotions/{promotion}', [AdminPromotionController::class, 'show'])->whereNumber('promotion');
        Route::put('/admin/promotions/{promotion}', [AdminPromotionController::class, 'update'])->whereNumber('promotion');
        Route::patch('/admin/promotions/{promotion}/status', [AdminPromotionController::class, 'status'])->whereNumber('promotion');
        Route::delete('/admin/promotions/{promotion}', [AdminPromotionController::class, 'destroy'])->whereNumber('promotion');
    });

    Route::middleware('can:activity.view')->group(function () {
        Route::get('/admin/activity/data', [AdminActivityController::class, 'index']);
        Route::get('/admin/activity/export', [AdminActivityController::class, 'export']);
        Route::get('/admin/activity/{entry}/detail', [AdminActivityController::class, 'show'])->whereNumber('entry');
    });

    Route::middleware('can:whatsapp.manage')->group(function () {
        Route::get('/admin/whatsapp/data', [AdminWhatsAppController::class, 'index']);
        Route::post('/admin/whatsapp/requests', [AdminWhatsAppController::class, 'store']);
        Route::put('/admin/whatsapp/quick-replies/{quickReply}', [AdminWhatsAppController::class, 'updateQuickReply'])->whereNumber('quickReply');
        Route::get('/admin/whatsapp/requests/{whatsappRequest}', [AdminWhatsAppController::class, 'show'])->whereNumber('whatsappRequest');
        Route::patch('/admin/whatsapp/requests/{whatsappRequest}/take', [AdminWhatsAppController::class, 'take'])->whereNumber('whatsappRequest');
        Route::patch('/admin/whatsapp/requests/{whatsappRequest}/assign', [AdminWhatsAppController::class, 'assign'])->whereNumber('whatsappRequest');
        Route::patch('/admin/whatsapp/requests/{whatsappRequest}/resolve', [AdminWhatsAppController::class, 'resolve'])->whereNumber('whatsappRequest');
        Route::patch('/admin/whatsapp/requests/{whatsappRequest}/reopen', [AdminWhatsAppController::class, 'reopen'])->whereNumber('whatsappRequest');
        Route::patch('/admin/whatsapp/requests/{whatsappRequest}/notes', [AdminWhatsAppController::class, 'notes'])->whereNumber('whatsappRequest');
    });

    Route::middleware('can:customers.view')->group(function () {
        Route::get('/admin/customers/data', [AdminCustomerController::class, 'index']);
        Route::get('/admin/customers/export', [AdminCustomerController::class, 'export']);
        Route::get('/admin/customers/{customer}/detail', [AdminCustomerController::class, 'show'])->whereNumber('customer');
    });
    Route::patch('/admin/customers/{customer}/marketing-opt-out', [AdminCustomerController::class, 'optOut'])->whereNumber('customer')->middleware('can:customers.manage');

    Route::middleware('can:coupons.manage')->group(function () {
        Route::get('/admin/coupons/data', [AdminCouponController::class, 'index']);
        Route::post('/admin/coupons', [AdminCouponController::class, 'store']);
        Route::get('/admin/coupons/{coupon}', [AdminCouponController::class, 'show'])->whereNumber('coupon');
        Route::put('/admin/coupons/{coupon}', [AdminCouponController::class, 'update'])->whereNumber('coupon');
        Route::patch('/admin/coupons/{coupon}/status', [AdminCouponController::class, 'status'])->whereNumber('coupon');
        Route::delete('/admin/coupons/{coupon}', [AdminCouponController::class, 'destroy'])->whereNumber('coupon');
    });

    Route::middleware('can:products.manage')->group(function () {
        Route::get('/admin/products/data', AdminProductController::class);
        Route::get('/admin/products/categories/data', [AdminCategoryController::class, 'index']);
        Route::post('/admin/products/categories', [AdminCategoryController::class, 'store']);
        Route::get('/admin/products/categories/{category}', [AdminCategoryController::class, 'show'])->whereNumber('category');
        Route::put('/admin/products/categories/{category}', [AdminCategoryController::class, 'update'])->whereNumber('category');
        Route::patch('/admin/products/categories/{category}/status', [AdminCategoryController::class, 'status'])->whereNumber('category');
        Route::get('/admin/products/{product}/detail', [AdminProductEditorController::class, 'show']);
        Route::post('/admin/products', [AdminProductEditorController::class, 'store']);
        Route::patch('/admin/products/{product}/status', [AdminProductController::class, 'status'])->whereNumber('product');
        Route::put('/admin/products/{product}', [AdminProductEditorController::class, 'update']);
        Route::post('/admin/products/{product}/variants', [AdminProductEditorController::class, 'storeVariant']);
        Route::put('/admin/products/{product}/variants/{variant}', [AdminProductEditorController::class, 'updateVariant']);
        Route::patch('/admin/products/{product}/variants/{variant}/status', [AdminProductEditorController::class, 'variantStatus'])->whereNumber(['product', 'variant']);
        Route::get('/admin/products/{product}/variants/{variant}/stock-movements', [AdminStockController::class, 'index'])->whereNumber(['product', 'variant']);
    });
    Route::post('/admin/products/{product}/variants/{variant}/stock', [AdminStockController::class, 'store'])->whereNumber(['product', 'variant'])->middleware('can:inventory.edit');

    Route::middleware('can:users.manage')->group(function () {
        Route::get('/admin/users/data', [AdminUserController::class, 'index']);
        Route::get('/admin/users/username-check', [AdminUserController::class, 'usernameCheck']);
        Route::post('/admin/users', [AdminUserController::class, 'store']);
        Route::get('/admin/users/{user}/detail', [AdminUserController::class, 'show'])->whereNumber('user');
        Route::put('/admin/users/{user}', [AdminUserController::class, 'update'])->whereNumber('user');
        Route::patch('/admin/users/{user}/deactivate', [AdminUserController::class, 'deactivate'])->whereNumber('user');
        Route::patch('/admin/users/{user}/reactivate', [AdminUserController::class, 'reactivate'])->whereNumber('user');
        Route::post('/admin/users/{user}/reset-password', [AdminUserController::class, 'resetPassword'])->whereNumber('user');
    });
});
Route::any('/admin/{path}', fn () => abort(404))->where('path', '.*');

Route::view('/', 'app', ['seo' => SpaRoutes::seoFor('/')]);
Route::get('/sitemap.xml', SitemapController::class);
Route::get('/promociones', PromotionsPageController::class);
Route::get('/producto/{slug}', [ProductPageController::class, 'show'])->where('slug', '[^/]+');

// Any other path: a storefront page that React renders (200) or, for everything else, a real 404 with the
// same shell (React shows the "not found" page). The API prefix is reserved for Laravel's own routes.
Route::fallback(function (Request $request) {
    $seo = SpaRoutes::seoFor($request->path());
    if ($seo === null) {
        return response()->view('app', ['seo' => SpaRoutes::notFoundSeo()], 404);
    }

    return response()->view('app', ['seo' => $seo]);
})->where('fallbackPlaceholder', '^(?!api(?:/|$)).*');
