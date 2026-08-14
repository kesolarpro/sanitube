<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default storage provider
    |--------------------------------------------------------------------------
    |
    | S3-compatible object storage is the recommended production target, but
    | SaniTube must remain fully usable on a single server or a shared cPanel
    | account with nothing but a local disk. The default is therefore "local":
    | an install works out of the box and is upgraded by changing one variable.
    |
    */

    'default' => env('SANITUBE_STORAGE_PROVIDER', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Each provider maps a SaniTube storage name to a Laravel filesystem disk
    | declared in config/filesystems.php. The driver decides which adapter is
    | used and, with it, whether expiring URLs are available.
    |
    | The S3, R2 and B2 providers require the `league/flysystem-aws-s3-v3`
    | package. It is intentionally not a hard dependency: installs that never
    | leave the local disk should not have to carry the AWS SDK.
    |
    */

    'providers' => [

        'local' => [
            'driver' => 'local',
            'disk' => 'sanitube',
        ],

        's3' => [
            'driver' => 's3',
            'disk' => 's3',
        ],

        'r2' => [
            'driver' => 'r2',
            'disk' => 'r2',
        ],

        'b2' => [
            'driver' => 'b2',
            'disk' => 'b2',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Signed URL lifetime
    |--------------------------------------------------------------------------
    |
    | How long a temporary URL handed to a browser or a distributor stays
    | valid, in seconds. Masters are never served through permanent URLs.
    |
    */

    'temporary_url_ttl' => (int) env('SANITUBE_TEMPORARY_URL_TTL', 900),

];
