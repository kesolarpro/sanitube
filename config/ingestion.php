<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Batch size
    |--------------------------------------------------------------------------
    |
    | The most sources one batch may contain. The initial catalogue is roughly
    | 900 files, so the default deliberately does not accommodate it in one go:
    | a batch is a unit of work that a person can inspect and, if it goes
    | wrong, reason about. Two batches of 500 that half-failed are diagnosable;
    | one batch of 900 is a wall of rows.
    |
    | Nothing here is an HTTP-sized limit. The request that creates a batch
    | carries references, not bytes, and every file is fetched by a queued job.
    |
    */

    'max_batch' => (int) env('SANITUBE_INGESTION_MAX_BATCH', 500),

    /*
    |--------------------------------------------------------------------------
    | Manual upload inbox
    |--------------------------------------------------------------------------
    |
    | Where a client's uploads wait before a batch refers to them. Reserved,
    | like `staging/`, so that "import everything under this prefix" can never
    | be pointed at the platform's own managed objects.
    |
    */

    'inbox_prefix' => 'inbox',

    /*
    |--------------------------------------------------------------------------
    | Retries
    |--------------------------------------------------------------------------
    |
    | A provider that times out is worth trying again; a checksum that does not
    | match is not. The job honours the failure code rather than retrying
    | everything the same number of times.
    |
    */

    'item_tries' => (int) env('SANITUBE_INGESTION_ITEM_TRIES', 3),
    'retry_backoff_seconds' => [30, 120, 600],

];
