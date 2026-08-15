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
            default => false,
        };
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
