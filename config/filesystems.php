<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

        /*
         * SaniTube asset storage.
         *
         * These disks hold masters, derivatives, artwork and distribution
         * exports. They are private by design and `throw` is enabled: losing a
         * master to a silently failed write is not an acceptable outcome.
         *
         * **None of them declares a `url`, including the `s3` disk above.**
         * That key is what makes `Storage::url()` return a permanent, public,
         * unexpiring address for every object on a disk, and a master is only
         * ever reached through a signed link that expires in minutes. STO-005
         * removed the three `*_URL` variables that used to be read here; the
         * storage contract no longer has a method that could return one, so
         * putting the key back would configure something nothing asks for.
         */

        'sanitube' => [
            'driver' => 'local',
            'root' => env('SANITUBE_LOCAL_STORAGE_ROOT', storage_path('app/sanitube')),
            'serve' => false,
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        'r2' => [
            'driver' => 's3',
            'key' => env('R2_ACCESS_KEY_ID'),
            'secret' => env('R2_SECRET_ACCESS_KEY'),
            'region' => env('R2_DEFAULT_REGION', 'auto'),
            'bucket' => env('R2_BUCKET'),
            'endpoint' => env('R2_ENDPOINT'),
            'use_path_style_endpoint' => env('R2_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        'b2' => [
            'driver' => 's3',
            'key' => env('B2_ACCESS_KEY_ID'),
            'secret' => env('B2_SECRET_ACCESS_KEY'),
            'region' => env('B2_DEFAULT_REGION'),
            'bucket' => env('B2_BUCKET'),
            'endpoint' => env('B2_ENDPOINT'),
            'use_path_style_endpoint' => env('B2_USE_PATH_STYLE_ENDPOINT', true),
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
