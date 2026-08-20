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
    | Depositing from a browser
    |--------------------------------------------------------------------------
    |
    | `deposit_per_request` bounds one *request*, not one import: a browser
    | holding nine hundred files asks in rounds rather than in a payload no
    | shared host will accept. `max_batch` above is the other limit and bounds
    | something else — how many files one batch may carry.
    |
    | `deposit_concurrency` is how many files the browser uploads at once. The
    | right answer is a property of the host rather than of the application:
    | an object store happily takes six, and a shared cPanel account relaying
    | through PHP starts refusing connections long before that. Low by
    | default — a slow import that finishes beats a fast one that takes the
    | site down.
    |
    */

    'deposit_per_request' => (int) env('SANITUBE_INGESTION_DEPOSIT_PER_REQUEST', 50),

    'deposit_concurrency' => (int) env('SANITUBE_INGESTION_DEPOSIT_CONCURRENCY', 3),

    /*
    |--------------------------------------------------------------------------
    | Deciding in bulk
    |--------------------------------------------------------------------------
    |
    | The most candidates one review action may decide about. It bounds a
    | *selection*, and the bound is the difference between "a selection" and
    | "everything, submitted by a script".
    |
    | Not a limit on how many can be decided in a day: the screen pages, and
    | a person works through nine hundred candidates a screenful at a time.
    | Each one queues a job, so this is also how many jobs one click may add.
    |
    */

    'max_bulk_review' => (int) env('SANITUBE_INGESTION_MAX_BULK_REVIEW', 200),

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

    /*
    |--------------------------------------------------------------------------
    | Manifest size
    |--------------------------------------------------------------------------
    |
    | The most rows one manifest may carry. A manifest is parsed into memory in
    | full — every reference has to be known before the batch is written, so
    | that a batch is never half-created — and a shared host has a modest
    | memory_limit. The ceiling is well above the batch limit on purpose: a
    | manifest that is merely too big for one batch should say so by name
    | rather than by exhausting memory.
    |
    */

    'manifest_max_rows' => (int) env('SANITUBE_INGESTION_MANIFEST_MAX_ROWS', 5000),

];
