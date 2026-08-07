<?php

use App\Models\AdminUser;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | CafeTrack is a stateless JSON API: there is no session guard and no web
    | login. Every request carries a bearer token.
    |
    */

    'defaults' => [
        'guard' => 'cafetrack',
        'passwords' => 'admin_users',
    ],

    /*
    |--------------------------------------------------------------------------
    | Guards
    |--------------------------------------------------------------------------
    |
    | The "cafetrack" driver is registered by AppServiceProvider via
    | Auth::viaRequest. It reads the bearer token, verifies the signature and
    | rejects any token whose `tv` claim no longer matches the user's
    | token_version (§7.1).
    |
    */

    'guards' => [
        'cafetrack' => [
            'driver' => 'cafetrack',
            'provider' => 'admin_users',
        ],
    ],

    'providers' => [
        'admin_users' => [
            'driver' => 'eloquent',
            'model' => AdminUser::class,
        ],
    ],

    'passwords' => [
        'admin_users' => [
            'provider' => 'admin_users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,

];
