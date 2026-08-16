<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\System;

use Illuminate\Http\RedirectResponse;
use SaniTube\Observability\Health\OperationalHealthProbe;
use SaniTube\Observability\Health\OperationalHealthStore;
use Throwable;

/**
 * The one place a probe may run from a request.
 *
 * Explicit, POST, and behind `can.role:administer`. Somebody pressed a button
 * that says "check now"; that is a different thing from a page load, and it is
 * the only exception to the rule that screens read stored state.
 *
 * A probe that throws does not fail the page. Reaching out to storage and to
 * three providers is precisely the operation that fails when something is
 * wrong, and an operations screen that 500s during an outage is an operations
 * screen that is useless when it is needed.
 */
final class RefreshHealthController
{
    public function __invoke(OperationalHealthProbe $probe, OperationalHealthStore $store): RedirectResponse
    {
        try {
            $stored = $store->put($probe->run());
        } catch (Throwable) {
            return back()->withErrors(['health' => 'PROBE_FAILED']);
        }

        // A refresh that could not store its result must say so rather than
        // leave a stale snapshot looking current.
        return $stored
            ? back()->with('status', 'system.health_refreshed')
            : back()->withErrors(['health' => 'SNAPSHOT_NOT_STORED']);
    }
}
