<?php

use App\Core\Env;

return [
    'name'     => Env::get('APP_NAME', 'Aalto Mart'),
    'env'      => Env::get('APP_ENV', 'production'),
    // Detailed errors are never shown in production, even if APP_DEBUG is left on
    'debug'    => (bool) Env::get('APP_DEBUG', false) && Env::get('APP_ENV', 'production') !== 'production',
    'url'      => rtrim((string) Env::get('APP_URL', ''), '/'),
    'timezone' => Env::get('APP_TIMEZONE', 'UTC'),

    'session' => [
        'name'   => Env::get('SESSION_NAME', 'aalto_session'),
        'secure' => (bool) Env::get('SESSION_SECURE', false),
    ],

    'admin' => [
        // seconds of inactivity before an admin is signed out
        'session_idle'     => (int) Env::get('ADMIN_SESSION_IDLE', 1800),
        // maximum seconds an admin session can last, active or not
        'session_lifetime' => (int) Env::get('ADMIN_SESSION_LIFETIME', 28800),
        // failed logins allowed per email + IP within the window
        'max_attempts'     => (int) Env::get('ADMIN_LOGIN_MAX_ATTEMPTS', 5),
        // failed logins allowed per IP (any email) within the window
        'max_attempts_ip'  => (int) Env::get('ADMIN_LOGIN_MAX_ATTEMPTS_IP', 20),
        'lockout_minutes'  => (int) Env::get('ADMIN_LOGIN_LOCKOUT_MINUTES', 15),
    ],

    // Image upload limits and standards: see app/config/images.php

    'store' => [
        'currency'        => Env::get('STORE_CURRENCY', 'BDT'),
        'currency_symbol' => Env::get('STORE_CURRENCY_SYMBOL', '৳'),
        // Delivery charges and free delivery: Admin -> Delivery (tables delivery_zones, settings)
        'email'           => Env::get('STORE_EMAIL', 'hello@example.com'),
        'phone'           => Env::get('STORE_PHONE', ''),
        // ISO 3166-1 country code for delivery (structured data)
        'country'         => Env::get('STORE_COUNTRY', 'BD'),
        // Return window promised on product pages (structured data)
        'return_days'     => (int) Env::get('STORE_RETURN_DAYS', 7),
    ],
];
