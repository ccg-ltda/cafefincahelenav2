<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'app');

// Reserve the API prefix and let Laravel's own routes take precedence.
Route::fallback(fn () => view('app'))
    ->where('fallbackPlaceholder', '^(?!api(?:/|$)).*');
