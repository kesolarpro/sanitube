<?php

declare(strict_types=1);

namespace SaniTube\Production;

/**
 * What a plan already has, before anything is made for it.
 *
 * **The count that stops the platform generating blindly.** Autonomous
 * generation without this is a machine that produces forty tracks because
 * forty is the number in the plan, regardless of the thirty-eight already
 * sitting unreleased — and every one of those forty is a paid call.
 *
 * Three numbers, kept apart because they answer different questions and
 * because collapsing them is how a plan double-orders:
 *
 *   - `onHand` — finished, unreleased, and available to use.
 *   - `inFlight` — asked for and not yet finished. Nothing to show for it, and
 *     ordering more because of that is exactly the mistake.
 *   - `target` — what the plan set out to make.
 *
 * `needed` is what remains after both, floored at zero. A plan that overshot is
 * not owed negative work.
 */
final readonly class ProductionInventory
{
    public function __construct(
        public int $onHand,
        public int $inFlight,
        public ?int $target,

        /**
         * Whether the platform can tell what this plan has at all.
         *
         * **False is not zero, and the difference decides whether money gets
         * spent.** A plan whose imprint names no artist has no attribution to
         * count through; reporting zero would invite generating, and the whole
         * point of an inventory is that it does not invite generating when it
         * does not know.
         */
        public bool $attributable = true,
    ) {}

    /**
     * How much more is worth asking for.
     *
     * **Null target means null need**, not unlimited need. A plan with no
     * target is one somebody drives by hand, and answering "how many more"
     * with a number would invent an intention nobody stated.
     */
    public function needed(): ?int
    {
        if ($this->target === null || ! $this->attributable) {
            return null;
        }

        return max(0, $this->target - $this->onHand - $this->inFlight);
    }

    /**
     * Whether making one more thing is justified right now.
     *
     * The question a worker asks before it spends anything. A plan with no
     * target answers **false**: an unstated intention is not a licence to
     * produce indefinitely, and a plan that wants unattended generation can
     * say how much it wants.
     */
    public function wantsMore(): bool
    {
        $needed = $this->needed();

        return $needed !== null && $needed > 0;
    }

    /**
     * Whether the plan has done what it set out to do.
     *
     * Counts `onHand` only. Work still in flight might fail, and marking a plan
     * exhausted on the strength of it would leave a target the plan then never
     * reaches with nothing running to reach it.
     */
    public function targetReached(): bool
    {
        return $this->attributable && $this->target !== null && $this->onHand >= $this->target;
    }

    /**
     * A machine-readable reason a slot was skipped, or null when it was not.
     */
    public function refusal(): ?string
    {
        // Asked first. "I cannot tell what you have" outranks every other
        // answer, because every other answer is a number this one says is
        // meaningless.
        if (! $this->attributable) {
            return 'NO_ATTRIBUTION';
        }

        if ($this->target === null) {
            return 'NO_TARGET';
        }

        if ($this->targetReached()) {
            return 'TARGET_REACHED';
        }

        return $this->wantsMore() ? null : 'ENOUGH_IN_FLIGHT';
    }
}
