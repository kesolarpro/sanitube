<?php

declare(strict_types=1);

namespace SaniTube\Operations\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Services\RecordAuditEvent;
use SaniTube\Operations\Models\BackgroundWorkState;

/**
 * The global stop.
 *
 * One switch, and it is deliberately blunt: when this installation is paused,
 * it takes on no new background work of any kind. Not "less", not "only the
 * important sort" — an operator reaching for this is usually watching something
 * go wrong and needs one action with an obvious effect, not a policy.
 *
 * **It is a database row rather than configuration.** A kill switch has to work
 * when the thing that is wrong is the cache, and it has to survive
 * `config:cache`, which on this platform's cPanel baseline runs at deploy time
 * and then not again for weeks. Somebody stopping a runaway backfill cannot be
 * asked to edit a file and redeploy.
 *
 * **It stops work being taken on, not work already running.** A job mid-flight
 * finishes: killing one halfway is how a half-written object or a half-imported
 * batch happens, and this switch exists to prevent damage rather than cause a
 * different sort. What it guarantees is that the backlog stops growing and
 * drains.
 */
final readonly class BackgroundWork
{
    public function __construct(private RecordAuditEvent $audit) {}

    public function isPaused(): bool
    {
        return $this->state()->is_paused;
    }

    /**
     * @param  string  $reason  a machine-readable code, never a sentence
     */
    public function pause(?User $by, string $reason): BackgroundWorkState
    {
        $state = $this->state();

        // Re-pausing keeps the original moment and the original reason. The
        // useful question is when this started, and a second press of the same
        // button must not reset the answer.
        if ($state->is_paused) {
            return $state;
        }

        $state->forceFill([
            'is_paused' => true,
            'reason' => mb_substr($reason, 0, 64),
            'paused_by_user_id' => $by?->id,
            'paused_at' => Carbon::now(),
        ])->save();

        $this->audit->record(AuditAction::BackgroundWorkPaused, reason: mb_substr($reason, 0, 64));

        return $state->refresh();
    }

    public function resume(?User $by): BackgroundWorkState
    {
        $state = $this->state();

        if (! $state->is_paused) {
            return $state;
        }

        $state->forceFill([
            'is_paused' => false,
            'reason' => null,
            'paused_by_user_id' => null,
            'paused_at' => null,
        ])->save();

        $this->audit->record(AuditAction::BackgroundWorkResumed);

        return $state->refresh();
    }

    /**
     * The single row, created on first read.
     *
     * So that an installation which has never been paused still has something
     * to report, and nothing above has to special-case its absence.
     */
    public function state(): BackgroundWorkState
    {
        $state = BackgroundWorkState::query()->orderBy('id')->first();

        if ($state instanceof BackgroundWorkState) {
            return $state;
        }

        return BackgroundWorkState::query()->create(['is_paused' => false]);
    }
}
