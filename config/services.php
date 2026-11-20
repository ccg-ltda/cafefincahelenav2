<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Wompi (pagos en línea). Los secretos viven SOLO aquí (servidor): nunca llegan al navegador, a los logs, al Historial ni a ninguna
    | respuesta JSON. `env` es sandbox | production y las 4 llaves deben corresponder a ese ambiente (WompiService::enabled()).
    */
    'wompi' => [
        'env' => env('WOMPI_ENV'),
        'public_key' => env('WOMPI_PUBLIC_KEY'),
        'private_key' => env('WOMPI_PRIVATE_KEY'),
        'integrity_secret' => env('WOMPI_INTEGRITY_SECRET'),
        'events_secret' => env('WOMPI_EVENTS_SECRET'),
        // Minutos que el cliente tiene para pagar en el Web Checkout (expiration-time de cada intento).
        'checkout_window_minutes' => (int) env('WOMPI_CHECKOUT_WINDOW_MINUTES', 30),
        // Minutos tras los que un pedido sin pago se cancela (si ningún intento sigue PENDING en Wompi; tope de 24 h).
        'pending_expiry_minutes' => (int) env('WOMPI_PENDING_EXPIRY_MINUTES', 60),
        // Pedidos MANUALES (los registra el administrador): horas que dura el link de pago y plazo para cancelar el pedido si nadie paga.
        'manual_pending_expiry_hours' => max(1, (int) env('WOMPI_MANUAL_PENDING_EXPIRY_HOURS', 24)),
    ],

];
