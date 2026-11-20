<?php

return [

    /*
    | Content-Security-Policy in "report only" mode: the browser logs what it WOULD block (console) but blocks nothing.
    | Production switch if a page breaks after a deploy: SECURITY_CSP_REPORT_ONLY=true (see DEPLOY.md).
    */
    'csp_report_only' => (bool) env('SECURITY_CSP_REPORT_ONLY', false),

    /*
    | Strict-Transport-Security max-age (seconds), only sent in production over HTTPS. One year.
    */
    'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),

    /*
    | File that `npm run dev` creates while the Vite dev server runs: while it exists (never in production) the CSP also allows that server.
    */
    'vite_hot_file' => public_path('hot'),

    /*
    | New passwords are checked against the Have I Been Pwned range API (only the first 5 characters of the hash leave the server).
    | If the service does not answer within the timeout the password is accepted anyway.
    */
    'check_pwned_passwords' => (bool) env('SECURITY_CHECK_PWNED', true),
    'pwned_timeout' => (int) env('SECURITY_PWNED_TIMEOUT', 3),

    /*
    | Admin sign-in: progressive lock per account (independent of the IP) and a more generous limit per IP.
    | `lock_after` failed attempts start the lock; each further failure uses the next duration (seconds) of `lock_steps`
    | (the last one repeats). The failure counter is forgotten after `forget_after` seconds without failures.
    */
    'login' => [
        'lock_after' => 5,
        'lock_steps' => [60, 300, 900],
        'forget_after' => 3600,
        'ip_attempts' => 60,
        'ip_window' => 60,
    ],
];
