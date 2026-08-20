<?php

declare(strict_types=1);

namespace SaniTube\Deduplication\Enums;

/**
 * What a person decided about a finding.
 *
 * Every finding starts `Proposed`, including the exact ones. That is the point
 * worth defending: byte identity is a fact, but *what to do about it* is not,
 * and a platform that auto-resolves the exact matches has taught its operators
 * that the queue only contains hard cases — which is exactly when a legitimate
 * second copy gets discarded without anyone reading the screen.
 *
 * Nothing here deletes anything. A confirmed duplicate is a duplicate that
 * somebody agreed is a duplicate; moving a master to the trash is a separate
 * act with its own record.
 */
enum DuplicateDecision: string
{
    case Proposed = 'PROPOSED';
    case Confirmed = 'CONFIRMED';
    case Rejected = 'REJECTED';

    public function isDecided(): bool
    {
        return $this !== self::Proposed;
    }
}
