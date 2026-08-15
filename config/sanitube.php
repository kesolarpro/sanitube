<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Modular monolith
    |--------------------------------------------------------------------------
    |
    | SaniTube is a modular monolith: one deployable application, explicit
    | module boundaries. Every module lives in its own directory under
    | `paths.modules` and is autoloaded under the `SaniTube\` namespace.
    |
    | A module is registered here *before* it has any code: the registry only
    | wires up what actually exists on disk (service provider, routes,
    | migrations, translations, views), so listing a planned module is free.
    |
    */

    'modules' => [
        'Identity',
        'Security',
        'Localization',
        'Assets',
        'Storage',
        'Ingestion',
        'MusicGeneration',
        'Catalog',
        'Artists',
        'Contributors',
        'Media',
        'AI',
        'Releases',
        'Rights',
        'Publishing',
        'Distribution',
        'Royalties',
        'Analytics',
        'Streaming',
        'Api',
        'Webhooks',
        'Audit',
        'Jobs',
        'Observability',
        'Ui',
        'Installer',
        'Deployment',
    ],

    'paths' => [
        'modules' => base_path('src'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP surface
    |--------------------------------------------------------------------------
    |
    | No domain is ever hard-coded in SaniTube. Everything that decides where
    | the application lives comes from the environment; these values only
    | describe the shape of the URLs, not the host they are served from.
    |
    */

    'api' => [
        'prefix' => env('SANITUBE_API_PREFIX', 'api'),
        'default_version' => env('SANITUBE_API_VERSION', 'v1'),
        'rate_limit_per_minute' => (int) env('SANITUBE_API_RATE_LIMIT', 60),

        /*
         * Read-only catalogue API. Like the health token, the routes return
         * 404 until this is set: an install that never configured an internal
         * consumer should not expose one.
         */
        'internal_token' => env('SANITUBE_INTERNAL_API_TOKEN'),
        'token_header' => 'X-SaniTube-Api-Token',
    ],

    /*
    |--------------------------------------------------------------------------
    | Health endpoints
    |--------------------------------------------------------------------------
    |
    | Liveness (`/up`) is always public: it proves the process answers and
    | discloses nothing. Readiness and the capability report describe the
    | environment in detail, so they stay disabled until a token is set and
    | are then only reachable with that token.
    |
    */

    'health' => [
        'token' => env('SANITUBE_HEALTH_TOKEN'),
        'header' => 'X-SaniTube-Health-Token',

        /*
         * Liveness is throttled like everything else, but it must never touch
         * the database — a probe that queries the database stops answering the
         * moment the database does, which is precisely when a load balancer
         * needs to know the process is alive. The default throttle store is
         * therefore the file cache, independent of CACHE_STORE.
         *
         * Limits are per client IP, per decay window.
         */
        'throttle' => [
            'store' => env('SANITUBE_HEALTH_THROTTLE_STORE', 'file'),

            'live' => [
                'max_attempts' => (int) env('SANITUBE_HEALTH_LIVE_RATE_LIMIT', 120),
                'decay_seconds' => 60,
            ],

            'privileged' => [
                'max_attempts' => (int) env('SANITUBE_HEALTH_PRIVILEGED_RATE_LIMIT', 30),
                'decay_seconds' => 60,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Runtime profile
    |--------------------------------------------------------------------------
    |
    | SaniTube must run on a shared cPanel account just as well as on a VPS.
    | The profile is a hint used by capability detection and the installers to
    | explain *why* an optional capability is missing, never to change domain
    | behaviour. Supported values: "auto", "shared", "vps".
    |
    */

    'runtime_profile' => env('SANITUBE_RUNTIME_PROFILE', 'auto'),

];
