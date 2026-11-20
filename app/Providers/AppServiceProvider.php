<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\NotPwnedVerifier;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The leaked-password check must never hold up saving a password: a short timeout, and the rule accepts the password if it fails.
        $this->app->singleton(UncompromisedVerifier::class, fn ($app) => new NotPwnedVerifier($app[HttpFactory::class], (int) config('security.pwned_timeout', 3)));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Production is HTTPS only: every generated URL (assets, redirects, Wompi's return URL) uses https.
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        // ->whereId('order') = a record id in the URL: plain digits of a sane length ("99999999999999999999" is a 404, not a database error).
        RouteInstance::macro('whereId', function (array|string $parameters) {
            /** @var RouteInstance $this */
            return $this->where(array_fill_keys((array) $parameters, '[0-9]{1,12}'));
        });

        // One Gate per permission, all from the single matrix in App\Support\Permissions (routes use `can:<permission>`).
        foreach (Permissions::ALL as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }

        // Anonymous menu clicks: generous for a human, small for a script. Keyed by IP in the limiter only (never stored).
        RateLimiter::for('whatsapp-clicks', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
        // Quote and order have their own buckets: unnamed throttles share one counter per IP, so ten quotes would block the order.
        RateLimiter::for('order-quote', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('order-store', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        // Wompi: the attempt and the result are public (own buckets, per IP); the webhook is generous because Wompi retries.
        RateLimiter::for('payment-attempt', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('payment-result', fn (Request $request) => Limit::perMinute(40)->by($request->ip()));
        RateLimiter::for('wompi-events', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
        // A customer coming back to a pending order: asking its state is cheap, cancelling is stricter. Per IP.
        RateLimiter::for('pending-order-status', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('pending-order-cancel', fn (Request $request) => Limit::perMinute(6)->by($request->ip()));
        // The administrator's manual order: product search, customer lookup and quote while typing (per person).
        RateLimiter::for('manual-order', fn (Request $request) => Limit::perMinute(120)->by('manual-order:'.($request->user()?->id ?? $request->ip())));
        // Strict limit for coupon validation (only quotes that carry a code count), so codes cannot be guessed by brute force.
        RateLimiter::for('coupons', fn (Request $request) => filled($request->input('coupon_code')) ? Limit::perMinute(10)->by($request->ip()) : Limit::none());
    }
}
