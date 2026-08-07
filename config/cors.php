<?php

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
|
| CafeTrack is an API-only backend: the Next.js frontend and the public
| check-in page run on their own origin and call it cross-origin.
|
| Origins are listed explicitly from CORS_ALLOWED_ORIGINS rather than opened
| to "*", because "*" would let any site a logged-in staff member visits call
| the API with their browser.
|
| Credentials stay off: auth is a bearer token in a header, not a cookie, so
| there is nothing for a cross-site request to carry implicitly.
|
*/

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000')),
)));

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // So the frontend can read the CSV and PDF download filenames.
    'exposed_headers' => ['Content-Disposition', 'Retry-After'],

    'max_age' => 3600,

    'supports_credentials' => false,

];
