<?php

declare(strict_types=1);

namespace SaniTube\Production\Exceptions;

use RuntimeException;
use SaniTube\Production\Enums\AutonomyMode;
use SaniTube\Production\Enums\ProductionPlanStatus;

/**
 * A plan the platform declined to write or to move.
 *
 * Machine-readable, like every refusal here; the English is for a log.
 */
final class ProductionPlanException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function nameRequired(): self
    {
        return new self('A production plan needs a name.', 'PLAN_NAME_REQUIRED');
    }

    public static function slugTaken(string $slug): self
    {
        return new self(sprintf('[%s] is already a production plan.', $slug), 'PLAN_SLUG_TAKEN');
    }

    /**
     * The imprint a plan produces for has been retired.
     *
     * Producing more work under an imprint somebody has stopped releasing is
     * the sort of thing that is only noticed at the point of delivery.
     */
    public static function profileInactive(): self
    {
        return new self('That editorial profile is no longer active.', 'PLAN_PROFILE_INACTIVE');
    }

    /**
     * A mode that cannot be set.
     *
     * Today that is `AUTONOMOUS_RELEASE` and only that. Handing a release to a
     * distributor is the one operation this platform cannot undo, and doing it
     * without a person is a decision nobody has made — so the refusal is in
     * code, where switching it on is a reviewable diff rather than an
     * environment value.
     */
    public static function modeLocked(AutonomyMode $mode): self
    {
        return new self(
            sprintf('[%s] is not a mode a plan may be set to.', $mode->value),
            'PLAN_AUTONOMY_LOCKED',
        );
    }

    /**
     * Resuming something that was not stopped, or was stopped in a way that
     * needs a decision first.
     */
    public static function notResumable(ProductionPlanStatus $status): self
    {
        return new self(
            sprintf('A plan that is %s cannot simply be resumed.', $status->value),
            'PLAN_NOT_RESUMABLE',
        );
    }
}
