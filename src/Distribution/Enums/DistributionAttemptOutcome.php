<?php

declare(strict_types=1);

namespace SaniTube\Distribution\Enums;

/**
 * How one attempt at talking to a distributor ended.
 *
 * Attempts are recorded separately from the delivery because "this release was
 * rejected" and "we tried four times, and the fourth was rejected for a
 * different reason than the first" are different facts — and only the second
 * one tells a label whether the problem is their metadata or the distributor.
 */
enum DistributionAttemptOutcome: string
{
    case Succeeded = 'SUCCEEDED';
    case Rejected = 'REJECTED';
    case Failed = 'FAILED';
}
