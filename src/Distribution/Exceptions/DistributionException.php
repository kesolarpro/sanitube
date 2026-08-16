<?php

declare(strict_types=1);

namespace SaniTube\Distribution\Exceptions;

use RuntimeException;
use SaniTube\Distribution\Enums\DistributionDeliveryStatus;

final class DistributionException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function distributorUnavailable(string $provider): self
    {
        return new self(
            sprintf('The [%s] distributor is not configured on this installation.', $provider),
            'DISTRIBUTOR_UNAVAILABLE',
        );
    }

    public static function releaseNotReady(): self
    {
        return new self(
            'Only a READY release can be delivered. A draft is still being assembled, and handing '
                .'one to a distributor is how a half-built package reaches stores.',
            'RELEASE_NOT_READY',
        );
    }

    public static function alreadySubmitted(DistributionDeliveryStatus $status): self
    {
        return new self(
            sprintf(
                'This release has already been handed to this distributor — it is %s. Submitting '
                    .'again is a second delivery of the same release, which stores read as a '
                    .'duplicate and which gets a catalogue flagged.',
                $status->value,
            ),
            'ALREADY_SUBMITTED',
        );
    }

    /**
     * @param  list<string>  $errors
     */
    public static function rejectedByDistributor(array $errors): self
    {
        return new self(
            'The distributor will not accept this release: '.implode('; ', $errors),
            'DISTRIBUTOR_REJECTED',
        );
    }

    public static function missingIdentifier(string $what): self
    {
        return new self(
            sprintf(
                '%s. DIST-001 never mints an identifier: an ISRC assigned automatically to a '
                    .'recording that already carries one is the mistake that cannot be taken back '
                    .'once a store has seen it.',
                $what,
            ),
            'MISSING_IDENTIFIER',
        );
    }

    public static function notReconcilable(DistributionDeliveryStatus $status): self
    {
        return new self(
            sprintf(
                'A delivery in [%s] has nothing to reconcile. Reconciliation answers "did our '
                    .'submission arrive", and that question only exists while the answer is unknown.',
                $status->value,
            ),
            'NOT_RECONCILABLE',
        );
    }

    public static function manualResolutionNeedsReference(): self
    {
        return new self(
            'Recording a submission as received requires the distributor\'s reference for it. '
                .'Without one the delivery can never be polled or taken down again.',
            'RESOLUTION_NEEDS_REFERENCE',
        );
    }

    public static function manualResolutionNeedsNote(): self
    {
        return new self(
            'Overruling the platform about what a distributor holds requires a stated reason. '
                .'It is the only record of where the person looked.',
            'RESOLUTION_NEEDS_NOTE',
        );
    }

    public static function notTakedownable(DistributionDeliveryStatus $status): self
    {
        return new self(
            sprintf('Nothing has been delivered to take down — this delivery is %s.', $status->value),
            'NOT_TAKEDOWNABLE',
        );
    }
}
