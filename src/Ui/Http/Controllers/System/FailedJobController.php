<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\System;

use Illuminate\Http\RedirectResponse;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Services\RecordAuditEvent;
use SaniTube\Observability\Exceptions\FailedJobException;
use SaniTube\Observability\Services\ResolveFailedJob;
use SaniTube\Ui\Queries\QueueQuery;

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
 *
 * **Both outcomes are audited, and the refusals are the interesting half.**
 * `FAILED_JOB_NOT_RETRYABLE` recorded eleven times in a minute is somebody
 * working through the list trying to get a submission to run again, and that
 * is precisely the attempt this platform refuses and should be able to show.
 * The failed job's uuid is the subject; its payload is not recorded here any
 * more than it is rendered — see {@see QueueQuery}.
 */
final class FailedJobController
{
    public function retry(string $uuid, ResolveFailedJob $jobs, RecordAuditEvent $audit): RedirectResponse
    {
        try {
            $jobs->retry($uuid);
        } catch (FailedJobException $exception) {
            $audit->refused(AuditAction::FailedJobRetried, $exception->reason, $uuid);

            return back()->withErrors(['jobs' => $exception->reason]);
        }

        $audit->record(AuditAction::FailedJobRetried, subjectUuid: $uuid);

        return back()->with('status', 'jobs.retried');
    }

    public function forget(string $uuid, ResolveFailedJob $jobs, RecordAuditEvent $audit): RedirectResponse
    {
        try {
            $jobs->forget($uuid);
        } catch (FailedJobException $exception) {
            $audit->refused(AuditAction::FailedJobForgotten, $exception->reason, $uuid);

            return back()->withErrors(['jobs' => $exception->reason]);
        }

        $audit->record(AuditAction::FailedJobForgotten, subjectUuid: $uuid);

        return back()->with('status', 'jobs.forgotten');
    }
}
