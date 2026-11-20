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
    | Audit trail (Historial). Fields whose NAME contains one of these words are never stored (they become "[oculto]").
    | `activity:prune` deletes only "auth" rows older than the retention days; administrative actions are kept.
    */
    'activity' => [
        'auth_retention_days' => (int) env('SHOP_ACTIVITY_AUTH_RETENTION_DAYS', 180),
        'redact' => ['password', 'remember_token', 'token', 'secret', 'api_key', 'apikey', 'signature', 'checksum', 'authorization', 'wompi', 'integrity', 'private_key', 'cookie'],
    ],

];
