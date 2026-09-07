<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing
|--------------------------------------------------------------------------
| The SPA is served from a different origin than the API, so the browser sends
| a preflight OPTIONS request before every non-simple call. These settings
| decide which origins it will accept an answer for.
*/

return [
    // No 'sanctum/csrf-cookie': this API is token-authenticated and stateless,
    // so the cookie/CSRF handshake that route exists for is never used.
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // Explicit origins, never '*'. Driven from the environment so staging and
    // production differ without a code change.
    'allowed_origins' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('FRONTEND_URLS', 'http://localhost:5173')))
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    // Cache the preflight for a day so the browser stops re-asking on every call.
    'max_age' => 60 * 60 * 24,

    // Authentication is a Bearer token in a header, not a cookie. Keeping this
    // false is what makes restricting origins cheap: there are no credentials
    // for a hostile origin to ride on in the first place.
    'supports_credentials' => false,
];
