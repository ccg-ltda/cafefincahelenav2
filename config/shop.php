<?php

return [

    /*
    | When true, only orders with payment_status "paid" count as real purchases (customers' totals, fulfillment).
    | While online payment does not exist (false), every order that is not cancelled counts.
    */
    'require_payment_before_fulfillment' => (bool) env('SHOP_REQUIRE_PAYMENT', false),

    /*
    | The store's WhatsApp number (country code + number, digits only, e.g. 573027880823). REQUIRED in production:
    | the storefront menu, footer, FAQ and product pages read it from here (published in a <meta> tag by the layout).
    */
    'whatsapp_number' => preg_replace('/\D/', '', (string) env('SHOP_WHATSAPP_NUMBER', '')),

    /*
    | Who receives the administrator emails (new paid order, payment on a cancelled order): SHOP_ADMIN_NOTIFICATION_EMAILS,
    | separated by commas. Invalid addresses are dropped. Empty = no administrator email is sent (a warning goes to the log).
    */
    'admin_notification_emails' => array_values(array_unique(array_filter(
        array_map(fn ($email) => strtolower(trim($email)), explode(',', (string) env('SHOP_ADMIN_NOTIFICATION_EMAILS', ''))),
        fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
    ))),

    /*
    | Audit trail (Historial). Fields whose NAME contains one of these words are never stored (they become "[oculto]").
    | `activity:prune` deletes only "auth" rows older than the retention days; administrative actions are kept.
    */
    'activity' => [
        'auth_retention_days' => (int) env('SHOP_ACTIVITY_AUTH_RETENTION_DAYS', 180),
        'redact' => ['password', 'remember_token', 'token', 'secret', 'api_key', 'apikey', 'signature', 'checksum', 'authorization', 'wompi', 'integrity', 'private_key', 'cookie'],
    ],

];
