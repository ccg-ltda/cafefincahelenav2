<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // One Gate per permission, all from the single matrix in App\Support\Permissions (routes use `can:<permission>`).
        foreach (Permissions::ALL as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }

        // Anonymous menu clicks: generous for a human, small for a script. Keyed by IP in the limiter only (never stored).
        RateLimiter::for('whatsapp-clicks', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
        // Quote and order have their own buckets: unnamed throttles share one counter per IP, so ten quotes would block the order.
        RateLimiter::for('order-quote', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('order-store', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('password-link', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        // Strict limit for coupon validation (only quotes that carry a code count), so codes cannot be guessed by brute force.
        RateLimiter::for('coupons', fn (Request $request) => filled($request->input('coupon_code')) ? Limit::perMinute(10)->by($request->ip()) : Limit::none());
    }
}
