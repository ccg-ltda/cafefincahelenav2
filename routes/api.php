<?php

use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{slug}', [ProductController::class, 'show']);
Route::post('/whatsapp/clicks', App\Http\Controllers\Api\WhatsAppClickController::class)->middleware('throttle:whatsapp-clicks');
Route::post('/orders/quote', [OrderController::class, 'quote'])->middleware(['throttle:order-quote', 'throttle:coupons']);
Route::post('/orders', [OrderController::class, 'store'])->middleware('throttle:order-store');
