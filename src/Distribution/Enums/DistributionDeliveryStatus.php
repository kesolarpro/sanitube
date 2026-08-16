<?php

declare(strict_types=1);

namespace SaniTube\Distribution\Enums;

use SaniTube\Distribution\DeliveryStatus;

/**
 * Where a delivery stands, in SaniTube's own terms.
 *
 * Deliberately not the same enum as {@see DeliveryStatus},
 * which is what a *distributor* last reported. Two vocabularies because they
 * answer two questions — the same split GEN-001 makes between a provider's job
 * state and the platform's belief about the row.
 *
 * Three of these have no provider counterpart at all: `VALIDATING`, `READY`
 * and `SUBMITTING` are entirely local, describing work SaniTube is doing
 * before or during a handover. Folding them into the provider's vocabulary
 * would mean inventing distributor states that do not exist.
 *
 * `ACCEPTED` and `DELIVERED` are separate on purpose. A distributor accepting
 * a package means the metadata passed its checks; delivering it means the
 * stores have it. Weeks can pass between the two, and a label chasing a
 * release needs to know which side of that line it is on.
 */
enum DistributionDeliveryStatus: string
{
    case Draft = 'DRAFT';
    case Validating = 'VALIDATING';
    case Ready = 'READY';
    case Submitting = 'SUBMITTING';

    /**
     * The request went out and no answer came back.
     *
     * Not FAILED and not SUBMITTED: SaniTube genuinely does not know whether
     * the distributor has the package. A read timeout, a reset connection or a
     * gateway error after the request was forwarded all land here, and every
     * one of them is compatible with the package having arrived.
     *
     * It is the *only* status that is neither retryable nor pending on the
     * distributor: nobody owes anybody an answer, because nobody knows there
     * is a question. It leaves this state by being reconciled — asked about by
     * idempotency key — or by a person who has looked.
     */
    case SubmittedUnconfirmed = 'SUBMITTED_UNCONFIRMED';

    case Submitted = 'SUBMITTED';
    case Accepted = 'ACCEPTED';
    case Delivered = 'DELIVERED';
    case Live = 'LIVE';
    case Rejected = 'REJECTED';
    case Failed = 'FAILED';
    case TakedownRequested = 'TAKEDOWN_REQUESTED';
    case TakenDown = 'TAKEN_DOWN';

    /**
     * Whether the distributor still owes an answer.
     */
    public function isPending(): bool
    {
        return match ($this) {
            self::Submitted, self::Accepted, self::Delivered, self::TakedownRequested => true,
            default => false,
        };
    }

    /**
     * Whether SaniTube may still submit this delivery.
     *
     * Once anything has been handed over, submitting again is a *second*
     * delivery of the same release — which is what a store sees as a duplicate
     * and what gets a label's catalogue flagged.
     */
    public function isSubmittable(): bool
    {
        return match ($this) {
            self::Draft, self::Validating, self::Ready, self::Rejected, self::Failed => true,
            // SUBMITTED_UNCONFIRMED is deliberately absent. Offering a retry
            // for a delivery that may already be in the distributor's system
            // is the duplicate this module exists to prevent — and the
            // idempotency key only saves a caller from it if the distributor
            // honours keys, which no contract can make it do.
            default => false,
        };
    }

    /**
     * Whether SaniTube can state where this delivery stands.
     *
     * The one honest use of "unknown" in this enum. Every other state is a
     * claim; this one is the absence of one.
     */
    public function isUnknown(): bool
    {
        return $this === self::SubmittedUnconfirmed;
    }

    public function isTerminal(): bool
    {
        return $this === self::TakenDown || $this === self::Rejected;
    }

    /**
     * Whether the release is on stores right now.
     */
    public function isPubliclyAvailable(): bool
    {
        return $this === self::Live;
    }

    /**
     * Whether a takedown can still be asked for.
     */
    public function isTakedownable(): bool
    {
        return match ($this) {
            self::Accepted, self::Delivered, self::Live => true,
            default => false,
        };
    }
}
