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

];
