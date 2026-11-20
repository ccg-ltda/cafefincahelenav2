<?php

/*
| CORS of the public API (/api/*): only the store's own origin (APP_URL) may call it from a browser. The storefront is served from
| the same origin, so it never needs more. No cookies are shared with other sites.
*/
$parts = parse_url((string) env('APP_URL', 'http://localhost'));
$origin = isset($parts['scheme'], $parts['host']) ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '') : 'http://localhost';

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],
    'allowed_origins' => [$origin],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Content-Type', 'Accept', 'X-Requested-With', 'X-Event-Checksum'],
    'exposed_headers' => [],
    'max_age' => 600,
    'supports_credentials' => false,
];
