<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | FFmpeg
    |--------------------------------------------------------------------------
    |
    | Audio analysis and derivative generation need FFmpeg. It is optional: an
    | install without it can still register assets and manage the catalogue,
    | it simply reports the analysis features as unavailable.
    |
    | On shared hosting FFmpeg is usually a static binary uploaded into the
    | account, so an absolute path is accepted as well as a PATH lookup.
    |
    */

    'ffmpeg' => [
        'path' => env('SANITUBE_FFMPEG_PATH', 'ffmpeg'),
        'timeout' => (int) env('SANITUBE_FFMPEG_TIMEOUT', 600),
    ],

    'ffprobe' => [
        'path' => env('SANITUBE_FFPROBE_PATH', 'ffprobe'),
        'timeout' => (int) env('SANITUBE_FFPROBE_TIMEOUT', 120),
    ],

];
