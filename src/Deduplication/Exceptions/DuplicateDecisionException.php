<?php

declare(strict_types=1);

namespace SaniTube\Deduplication\Exceptions;

use RuntimeException;

final class DuplicateDecisionException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    /**
     * The finding already says what this decision would say.
     *
     * Refused rather than quietly re-saved, so that a double-submitted form
     * does not rewrite `decided_by` and `decided_at` and make it look as
     * though somebody answered twice.
     */
    public static function alreadyDecided(string $decision): self
    {
        return new self(sprintf('The finding is already %s.', $decision), 'DUPLICATE_ALREADY_DECIDED');
    }
}
