<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\StatsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
| Mounted under /api by bootstrap/app.php, so every route below is reachable
| at /api/v1/... . Versioning in the URL means a future v2 can change response
| shapes without breaking already-shipped mobile clients.
*/

Route::prefix('v1')->group(function (): void {

    // ---------------------------------------------------------------------
    // Public
    // ---------------------------------------------------------------------
    Route::post('register', [AuthController::class, 'register'])
        ->middleware('throttle:auth');

    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:auth');

    // Catalogue browsing needs no account -- guests must be able to shop
    // before they sign up.
    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('categories/{category}', [CategoryController::class, 'show']);
    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/{product}', [ProductController::class, 'show'])->whereNumber('product');
    Route::get('products/{product}/reviews', [ReviewController::class, 'index'])->whereNumber('product');

    // ---------------------------------------------------------------------
    // Authenticated
    // ---------------------------------------------------------------------
    Route::middleware('auth:sanctum')->group(function (): void {

        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('user', [AuthController::class, 'user']);

        // Cart: one active cart per authenticated customer.
        Route::get('cart', [CartController::class, 'show']);
        Route::post('cart/items', [CartController::class, 'storeItem']);
        Route::patch('cart/items/{item}', [CartController::class, 'updateItem']);
        Route::delete('cart/items/{item}', [CartController::class, 'destroyItem']);

        // Orders. Checkout carries its own tighter rate limit because each call
        // opens a transaction and takes row locks.
        Route::post('orders', [OrderController::class, 'store'])->middleware('throttle:checkout');
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{order}', [OrderController::class, 'show']);
        Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus']);
        Route::patch('orders/{order}/cancel', [OrderController::class, 'cancel']);

        Route::post('products/{product}/reviews', [ReviewController::class, 'store'])->whereNumber('product');
        Route::delete('reviews/{review}', [ReviewController::class, 'destroy']);

        // Catalogue writes. The role middleware is a coarse first gate; the
        // per-record ownership check still happens in ProductPolicy.
        Route::middleware('role:seller,admin')->group(function (): void {
            Route::post('products', [ProductController::class, 'store']);
            Route::patch('products/{product}', [ProductController::class, 'update'])->whereNumber('product');
            Route::delete('products/{product}', [ProductController::class, 'destroy'])->whereNumber('product');
            Route::get('stats', StatsController::class);
        });

        // Taxonomy is platform-owned.
        Route::middleware('role:admin')->group(function (): void {
            Route::post('categories', [CategoryController::class, 'store']);
            Route::patch('categories/{category}', [CategoryController::class, 'update']);
            Route::delete('categories/{category}', [CategoryController::class, 'destroy']);
        });
    });
});
