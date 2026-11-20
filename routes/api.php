<?php

use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PendingOrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\WompiPaymentController;
use Illuminate\Support\Facades\Route;

Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{slug}', [ProductController::class, 'show']);
Route::post('/whatsapp/clicks', App\Http\Controllers\Api\WhatsAppClickController::class)->middleware('throttle:whatsapp-clicks');
Route::post('/orders/quote', [OrderController::class, 'quote'])->middleware(['throttle:order-quote', 'throttle:coupons']);
Route::post('/orders', [OrderController::class, 'store'])->middleware('throttle:order-store');

// Wompi payments: the attempt (reference + email), the result (asks Wompi) and the webhook (checksum, outside CSRF like every /api route).
// A customer who left for Wompi and came back: ask the state of their pending order / cancel it. Reference + email, generic answers.
Route::post('/orders/pending/status', [PendingOrderController::class, 'status'])->middleware('throttle:pending-order-status');
Route::post('/orders/pending/cancel', [PendingOrderController::class, 'cancel'])->middleware('throttle:pending-order-cancel');
Route::post('/payments/wompi/attempt', [WompiPaymentController::class, 'attempt'])->middleware('throttle:payment-attempt');
Route::get('/payments/wompi/result', [WompiPaymentController::class, 'result'])->middleware('throttle:payment-result');
Route::post('/payments/wompi/events', [WompiPaymentController::class, 'events'])->middleware('throttle:wompi-events');
