<?php

declare(strict_types=1);

namespace SaniTube\Production\Enums;

/**
 * Where one piece of scheduled work stands.
 *
 * A slot is a **standing invitation to do something**, not the doing of it. It
 * is opened by the scheduler, claimed by whatever does the work, and finished
 * one way or another — and the states exist so that "why did nothing happen on
 * the 14th" has an answer other than silence.
 */
enum ProductionSlotStatus: string
{
    /** Opened, and nothing has taken it yet. */
    case Pending = 'PENDING';

    /**
     * Something is working on it.
     *
     * Claimed atomically, so two workers cannot both be here. The claim is the
     * whole reason this state exists: without it a slot is either "not done"
     * or "done", and a worker that died halfway is indistinguishable from one
     * that never started.
     */
    case Claimed = 'CLAIMED';

    /** The work happened. */
    case Completed = 'COMPLETED';

    /**
     * A person called it off.
     *
     * Distinct from `Skipped`, which the platform decides. Somebody looking at
     * a month of slots needs to see which gaps were their own doing.
     */
    case Cancelled = 'CANCELLED';

    /**
     * The platform decided there was nothing to do.
     *
     * The ordinary outcome of an inventory check that found enough already, or
     * of a plan that reached its target between the slot being opened and
     * being claimed. **Not a failure**, and reported separately from one.
     */
    case Skipped = 'SKIPPED';

    /**
     * The work was attempted and did not finish.
     *
     * Carries a reason. Distinct from `Skipped` because the remedies differ: a
     * skip is information and a failure is something to look at.
     */
    case Failed = 'FAILED';

    /**
     * A supplier was asked and nobody knows what came back.
     *
     * **Distinct from `Failed`, and the distinction is the whole point.** A
     * failure is something that did not happen; this is something that may
     * have happened. A worker that reached a supplier and then died leaves no
     * record of the answer, and the two possibilities — the request never
     * arrived, or it arrived and produced a track somebody has been charged
     * for — call for different actions.
     *
     * So the platform stops rather than guessing. Retrying would risk paying
     * twice for one occasion; recording a failure would tell an operator
     * nothing happened, which may be false. What it needs is a person to look
     * at the supplier's own record, which is exactly what this state asks for.
     *
     * Settled, because nothing further will happen on its own. PROD-005
     * reaches it only through the reaper.
     *
     * `UNKNOWN_RESULT` rather than `UNKNOWN`: the platform is not unsure what
     * state this occasion is in -- it knows exactly, which is that the *result*
     * was lost. The longer name also keeps it apart from the `UNKNOWN` that
     * commercial rights use, where "not yet determined" is the ordinary
     * starting state and wants none of the alarm this one does.
     */
    case Unknown = 'UNKNOWN_RESULT';

    /**
     * Whether this slot is still available to be claimed.
     */
    public function isClaimable(): bool
    {
        return $this === self::Pending;
    }

    /**
     * Whether anything more will happen to this slot.
     */
    public function isSettled(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::Skipped, self::Failed, self::Unknown], true);
    }

    /**
     * Whether a person put it in this state.
     *
     * Reported separately on a screen, for the reason a plan's states are: a
     * list that mixed deliberate cancellations in with the platform's own
     * decisions tells an operator nothing about which need them.
     */
    public function wasSetByAPerson(): bool
    {
        return $this === self::Cancelled;
    }
}
