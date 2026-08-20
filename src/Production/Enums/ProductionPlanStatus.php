<?php

declare(strict_types=1);

namespace SaniTube\Production\Enums;

/**
 * Where a production plan stands.
 *
 * Five states, and the distinctions between them are what make the platform's
 * behaviour explicable to whoever finds a plan that has stopped. "It is not
 * running" is not an answer; each of these says *why*, and three of them have
 * different remedies.
 */
enum ProductionPlanStatus: string
{
    /** Running. The platform may act within the plan's autonomy mode. */
    case Active = 'ACTIVE';

    /**
     * A person stopped it.
     *
     * Resumes exactly where it was. Distinct from `Disabled` because pausing is
     * a temporary act by somebody who intends to come back, and an interface
     * that offered one control for both would make "pause for a fortnight" and
     * "we are not doing this any more" the same button.
     */
    case Paused = 'PAUSED';

    /**
     * The plan did what it set out to do.
     *
     * Not a failure and not a stop somebody chose — the target was reached.
     * Separate from `Disabled` because the remedy is different: an exhausted
     * plan is raised, extended or finished, and a disabled one is reconsidered.
     */
    case Exhausted = 'EXHAUSTED';

    /**
     * The platform stopped itself and wants a person.
     *
     * The state that makes autonomy safe to switch on. Something happened that
     * the plan cannot resolve on its own — a provider went away, a proposal
     * came back empty, an inventory said there is nothing to work with — and
     * rather than continue or fail silently, the plan says so and waits.
     *
     * **Distinct from `Paused`, and the difference matters most here.** Paused
     * means a person stopped it; this means the platform did. Collapsing the
     * two would hide every self-stop inside a state that looks deliberate.
     */
    case ReviewRequired = 'REVIEW_REQUIRED';

    /**
     * Switched off, indefinitely, by a person.
     *
     * Kept rather than deleted: the work done under it still refers to it, and
     * removing the row would leave releases describing a plan that does not
     * exist.
     */
    case Disabled = 'DISABLED';

    /**
     * Whether the platform may act on this plan right now.
     *
     * Only one state says yes, and that asymmetry is deliberate. Anything the
     * platform is unsure about should stop it acting, not merely be recorded
     * beside it acting.
     */
    public function isRunnable(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether a person can put this plan back to work without deciding
     * anything else first.
     */
    public function isResumable(): bool
    {
        return $this === self::Paused || $this === self::ReviewRequired;
    }

    /**
     * Whether the platform put the plan in this state, rather than a person.
     *
     * Reported separately on a screen: a list mixing self-stops in with
     * deliberate ones tells an operator nothing about which needs them.
     */
    public function wasSetByThePlatform(): bool
    {
        return $this === self::Exhausted || $this === self::ReviewRequired;
    }
}
