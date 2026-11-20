<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every answer: CSP (frame-ancestors 'none' keeps the panel out of other people's iframes),
 * nosniff, Referrer-Policy, Permissions-Policy and, only in production over HTTPS, HSTS. It also drops X-Powered-By.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // One-time token for the few inline <script> of our own views (the e-mail preview): nonce="{{ request()->attributes->get('csp_nonce') }}".
        $request->attributes->set('csp_nonce', $nonce = base64_encode(random_bytes(16)));
        Vite::useCspNonce($nonce); // the inline snippet of Vite's dev server (react-refresh) gets the nonce too

        $response = $next($request);

        $response->headers->set(config('security.csp_report_only') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy', $this->policy($request));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
        if (app()->isProduction() && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age='.(int) config('security.hsts_max_age').'; includeSubDomains');
        }
        $response->headers->remove('X-Powered-By');
        header_remove('X-Powered-By');

        return $response;
    }

    /**
     * What the pages need: our own scripts/styles/images, Google Fonts (CSS and files), `data:`/`blob:` images (the image picker preview)
     * and the host of APP_URL for images (public storage URLs are absolute). Wompi and WhatsApp are reached by NAVIGATION (a
     * redirect or a link), which CSP does not restrict. While `npm run dev` is running (public/hot) the Vite server is allowed too.
     */
    private function policy(Request $request): string
    {
        $self = $request->getSchemeAndHttpHost();
        $app = $this->origin((string) config('app.url'));
        $images = trim("'self' data: blob: ".($app && $app !== $self ? $app : ''));
        $script = ["'self'", "'nonce-".$request->attributes->get('csp_nonce')."'"];
        $style = ["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com'];
        $connect = ["'self'"];
        $fonts = ["'self'", 'data:', 'https://fonts.gstatic.com'];

        if ($dev = $this->viteDevServer()) {
            // CSP cannot name an IPv6 address (`http://[::1]:5173` is an invalid source, which is what Vite writes in public/hot), so for
            // those the dev server is allowed by scheme. This branch never runs in production.
            $socket = preg_replace('#^http#', 'ws', $dev);
            if (str_contains($dev, '[')) {
                [$dev, $socket] = [strtok($dev, ':').':', strtok($socket, ':').':'];
            }
            $script[] = $dev;
            $style[] = $dev;
            array_push($connect, $dev, $socket);
            $fonts[] = $dev;
            $images .= ' '.$dev;
        }

        $directives = [
            "default-src 'self'",
            'script-src '.implode(' ', $script),
            'style-src '.implode(' ', $style),
            'img-src '.$images,
            'font-src '.implode(' ', $fonts),
            'connect-src '.implode(' ', $connect),
            "frame-src 'none'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ];
        if (app()->isProduction() && $request->isSecure()) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directives);
    }

    private function viteDevServer(): ?string
    {
        $file = (string) config('security.vite_hot_file', public_path('hot'));
        if (app()->isProduction() || ! is_file($file)) {
            return null;
        }

        return $this->origin(trim((string) file_get_contents($file)));
    }

    private function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
