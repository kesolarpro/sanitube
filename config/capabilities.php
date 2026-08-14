<?php

declare(strict_types=1);

use SaniTube\Observability\Capabilities\Detectors\DatabaseDetector;
use SaniTube\Observability\Capabilities\Detectors\FfmpegDetector;
use SaniTube\Observability\Capabilities\Detectors\ImageProcessingDetector;
use SaniTube\Observability\Capabilities\Detectors\MailDetector;
use SaniTube\Observability\Capabilities\Detectors\ObjectStorageDetector;
use SaniTube\Observability\Capabilities\Detectors\PhpExtensionsDetector;
use SaniTube\Observability\Capabilities\Detectors\PhpRuntimeDetector;
use SaniTube\Observability\Capabilities\Detectors\QueueDetector;
use SaniTube\Observability\Capabilities\Detectors\RedisDetector;
use SaniTube\Observability\Capabilities\Detectors\SchedulerDetector;
use SaniTube\Observability\Capabilities\Detectors\StoragePermissionsDetector;

return [

    /*
    |--------------------------------------------------------------------------
    | Capability detectors
    |--------------------------------------------------------------------------
    |
    | SaniTube runs on everything from a shared cPanel account to a dedicated
    | server, so it never assumes: it looks. Each detector answers one question
    | about the running environment and, when the answer is "no", explains what
    | to do about it instead of failing at the first request.
    |
    | Order is display order, from "the application cannot run" down to
    | "optional optimisation".
    |
    */

    'detectors' => [
        PhpRuntimeDetector::class,
        PhpExtensionsDetector::class,
        DatabaseDetector::class,
        StoragePermissionsDetector::class,
        ObjectStorageDetector::class,
        QueueDetector::class,
        SchedulerDetector::class,
        ImageProcessingDetector::class,
        FfmpegDetector::class,
        MailDetector::class,
        RedisDetector::class,
    ],

];
