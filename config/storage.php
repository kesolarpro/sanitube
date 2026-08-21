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

    /*
    | Each provider names its own disk and its own environment variables.
    |
    | **The variable names are data because they differ per provider**, and
    | STO-004 exists because they were assumed to be the same. The settings
    | screen read credential *presence* from the right disk -- correctly -- and
    | told the operator to edit `AWS_ACCESS_KEY_ID`, which is the S3 disk's
    | variable. On an R2 installation that variable is read by nothing: the r2
    | disk takes `R2_ACCESS_KEY_ID`. An operator following the screen would set
    | a variable that does nothing, watch it still report "not configured", and
    | reasonably conclude the platform was broken.
    |
    | `endpoint` is deliberately absent for `s3` -- Amazon's is derived from the
    | region -- and present for R2 and B2, which are addressed by account.
    |
    | No `url` here, for any provider. That key makes Laravel serve a permanent
    | public address, and SaniTube reaches audio through expiring signed links
    | only. Offering it in configuration would be offering a public bucket.
    */
    'providers' => [

        'local' => [
            'driver' => 'local',
            'disk' => 'sanitube',
            // Nothing to configure. A local disk needs no credential, which is
            // a fact worth showing rather than an empty form to puzzle over.
            'credentials' => [],
            'settings' => [],
        ],

        's3' => [
            'driver' => 's3',
            'disk' => 's3',
            'credentials' => [
                'key' => 'AWS_ACCESS_KEY_ID',
                'secret' => 'AWS_SECRET_ACCESS_KEY',
                'bucket' => 'AWS_BUCKET',
            ],
            'settings' => [
                'region' => 'AWS_DEFAULT_REGION',
            ],
        ],

        'r2' => [
            'driver' => 'r2',
            'disk' => 'r2',
            'credentials' => [
                'key' => 'R2_ACCESS_KEY_ID',
                'secret' => 'R2_SECRET_ACCESS_KEY',
                'bucket' => 'R2_BUCKET',
            ],
            'settings' => [
                // An address, not a credential. Which endpoint an installation
                // talks to is exactly what an operator needs to see when a
                // deployment is pointed at the wrong account.
                'endpoint' => 'R2_ENDPOINT',
                'region' => 'R2_DEFAULT_REGION',
            ],
        ],

        'b2' => [
            'driver' => 'b2',
            'disk' => 'b2',
            'credentials' => [
                'key' => 'B2_ACCESS_KEY_ID',
                'secret' => 'B2_SECRET_ACCESS_KEY',
                'bucket' => 'B2_BUCKET',
            ],
            'settings' => [
                'endpoint' => 'B2_ENDPOINT',
                'region' => 'B2_DEFAULT_REGION',
            ],
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
