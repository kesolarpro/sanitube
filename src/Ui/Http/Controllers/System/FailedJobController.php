<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\System;

use Illuminate\Http\RedirectResponse;
use SaniTube\Observability\Exceptions\FailedJobException;
use SaniTube\Observability\Services\ResolveFailedJob;

/**
 * What an operator may do about a job that failed.
 *
 * Behind `can.role:administer`, on the route: a failed-jobs listing already
 * says what the installation is doing and how it is configured, and acting on
 * one puts work back on the queue.
 *
 * Both actions are POSTs identified by the failed job's uuid — never by the
 * `failed_jobs` row id, which is a counter somebody can walk.
 *
 * Refusals travel as codes. `ResolveFailedJob` decides; nothing here
 * re-implements which jobs are safe to run again, because a second copy of
 * that judgement is the copy that eventually says yes to the wrong one.
 */
final class FailedJobController
{
    public function retry(string $uuid, ResolveFailedJob $jobs): RedirectResponse
    {
        try {
            $jobs->retry($uuid);
        } catch (FailedJobException $exception) {
            return back()->withErrors(['jobs' => $exception->reason]);
        }

        return back()->with('status', 'jobs.retried');
    }

    public function forget(string $uuid, ResolveFailedJob $jobs): RedirectResponse
    {
        try {
            $jobs->forget($uuid);
        } catch (FailedJobException $exception) {
            return back()->withErrors(['jobs' => $exception->reason]);
        }

        return back()->with('status', 'jobs.forgotten');
    }
}
