<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\System;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use SaniTube\Operations\Services\BackgroundWork;
use SaniTube\Ui\Http\Requests\System\PauseBackgroundWorkRequest;

/**
 * The global stop, reachable without a shell.
 *
 * OPS-002. `sanitube:work:pause` has existed and worked since the switch was
 * built, and it was the only way to press it — so the control an operator
 * reaches for **while watching something go wrong** required SSH, at the moment
 * they are least able to go and find a terminal.
 *
 * It is deliberately blunt, and that is the service's design rather than this
 * controller's: paused means no new background work of any kind. Not "less",
 * not "only the important sort". Somebody pressing this needs one action with
 * an obvious effect.
 *
 * **It stops work being taken on, not work already running.** A job mid-flight
 * finishes, because killing one halfway is how a half-written object happens —
 * and this switch exists to prevent damage rather than cause a different sort.
 * The screen says so, so that nobody presses it expecting an immediate stop.
 */
final class BackgroundWorkController
{
    public function pause(PauseBackgroundWorkRequest $request, BackgroundWork $work): RedirectResponse
    {
        /** @var User $operator */
        $operator = $request->user();

        $work->pause($operator, $request->reason());

        return back();
    }

    public function resume(Request $request, BackgroundWork $work): RedirectResponse
    {
        /** @var User $operator */
        $operator = $request->user();

        $work->resume($operator);

        return back();
    }
}
