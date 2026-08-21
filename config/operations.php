<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Backlog ceiling
    |--------------------------------------------------------------------------
    |
    | The most jobs this installation will let sit in the queue. A request that
    | would push it past this is refused whole rather than half-enqueued, so
    | nothing is stranded in a state nobody is watching.
    |
    | The default assumes this platform's baseline: a database queue drained by
    | a cron-driven worker on a shared account, where a runaway backfill is not
    | slow but fatal. A machine with a real worker can raise it, and `0` removes
    | the ceiling entirely -- a legitimate setting, and the reason this is a
    | count rather than a proportion of anything: a fraction of an unknown
    | machine is not a limit anybody can reason about.
    |
    */

    'backlog' => [
        'ceiling' => (int) env('SANITUBE_BACKLOG_CEILING', 10000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Disk thresholds
    |--------------------------------------------------------------------------
    |
    | When the doctor starts warning about free space, in megabytes. Absolute
    | rather than a percentage: five percent of a 4TB volume is a comfortable
    | 200GB, five percent of a 20GB VPS is an outage in progress, and what the
    | platform actually needs — room for a master upload, a backup, a log — is
    | measured in megabytes, not in fractions of somebody's disk.
    |
    | `warn` is the early word; `blocker` is where a deploy should stop, since
    | migrating and rebuilding caches on a full disk fails halfway in the
    | worst possible way. 0 disables either.
    |
    */

    'disk' => [
        'warn_below_mb' => (int) env('SANITUBE_DISK_WARN_MB', 2048),
        'blocker_below_mb' => (int) env('SANITUBE_DISK_BLOCKER_MB', 256),
    ],

];
