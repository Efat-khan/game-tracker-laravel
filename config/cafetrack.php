<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Auth tokens
    |--------------------------------------------------------------------------
    |
    | Claims are sub, email, role, cafe (nullable), tv (token version) and exp.
    | `tv` is checked against admin_users.token_version on every request, so
    | bumping that column signs a user out of every device at once.
    |
    | The secret falls back to APP_KEY. Point it at the FastAPI reference's
    | SECRET_KEY to have both backends mint tokens the other accepts.
    |
    */

    'jwt_secret' => env('JWT_SECRET') ?: env('APP_KEY'),

    'jwt_ttl_hours' => (int) env('JWT_TTL_HOURS', 12),

    /*
    |--------------------------------------------------------------------------
    | Public check-in
    |--------------------------------------------------------------------------
    |
    | Station ids are sequential, so a bare /checkin/{id} link is guessable.
    | Every QR code carries the first 16 hex chars of
    | HMAC-SHA256(APP_KEY, "station:{id}").
    |
    */

    'frontend_base_url' => rtrim((string) env('FRONTEND_BASE_URL', 'http://localhost:3000'), '/'),

    'require_qr_token' => filter_var(env('REQUIRE_QR_TOKEN', true), FILTER_VALIDATE_BOOL),

    'qr_secret' => env('APP_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Rate limits (per client IP, sliding one-minute window). 0 disables.
    |--------------------------------------------------------------------------
    */

    'rate_limits' => [
        'checkin' => (int) env('RATE_LIMIT_CHECKIN', 10),
        'login' => (int) env('RATE_LIMIT_LOGIN', 10),
        'public_station' => (int) env('RATE_LIMIT_PUBLIC_STATION', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-cafe billing defaults
    |--------------------------------------------------------------------------
    |
    | Overridden per cafe in app_settings, which is keyed on (cafe_id, key).
    |
    */

    'settings_defaults' => [
        'billing_round_minutes' => 15,
        'round_amount_to' => 5,
        'open_hour' => 10,
        'close_hour' => 23,
    ],

];
