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

    /**
     * The request went out and no answer came back.
     *
     * A fourth outcome rather than a flavour of FAILED, because FAILED is what
     * retry logic reads. "It did not work" and "we do not know whether it
     * worked" call for opposite next moves, and a log that records them the
     * same way cannot tell anybody which one happened.
     */
    case Unknown = 'UNKNOWN';
}
