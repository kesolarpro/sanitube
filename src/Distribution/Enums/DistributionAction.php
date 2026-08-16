<?php

declare(strict_types=1);

namespace SaniTube\Distribution\Enums;

/**
 * What an attempt was trying to do.
 */
enum DistributionAction: string
{
    case Validate = 'VALIDATE';
    case Prepare = 'PREPARE';
    case Submit = 'SUBMIT';
    case Poll = 'POLL';
    case Takedown = 'TAKEDOWN';

    /**
     * Asking a distributor whether it already holds a submission, or a person
     * saying what they found when they looked.
     *
     * Separate from POLL: polling asks "where has this got to" about a
     * delivery everybody agrees exists. Reconciling asks whether it exists
     * at all.
     */
    case Reconcile = 'RECONCILE';
}
