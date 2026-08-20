<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Claim lease
    |--------------------------------------------------------------------------
    |
    | How long a worker may hold an occasion before another may take it back.
    |
    | The lease exists because a worker can die. PROD-002 made claiming atomic
    | so two workers cannot both take one occasion; nothing made a claim
    | *expire*, so a process killed between claiming and acting left the
    | occasion CLAIMED for ever, invisible to everything — the scheduler will
    | not re-open a date that already has a slot, and no worker will take one
    | that is not PENDING.
    |
    | An hour, not a minute. The number is a trade in one direction only: too
    | short and a slow worker has its occasion taken from underneath it, which
    | on a plan that may act alone means two suppliers asked for one occasion.
    | Too long only means a stuck occasion is noticed late. An hour is far
    | longer than any claim-to-request path in this platform and short enough
    | that a crash is recovered the same day.
    |
    | Reclaiming is deliberately conservative and never guesses: see
    | SaniTube\Production\Services\ReclaimProductionSlots for what it will and
    | will not take back.
    |
    */

    'claim_lease_seconds' => (int) env('SANITUBE_PRODUCTION_CLAIM_LEASE_SECONDS', 3600),

    /*
    |--------------------------------------------------------------------------
    | Reclaim batch
    |--------------------------------------------------------------------------
    |
    | How many abandoned occasions one pass may examine. Bounded so that a
    | recovery run after a long outage is a series of short passes rather than
    | one that a shared host kills halfway through — and so that reclaiming
    | never starves the work it exists to unblock.
    |
    */

    'reclaim_batch' => (int) env('SANITUBE_PRODUCTION_RECLAIM_BATCH', 100),

];
