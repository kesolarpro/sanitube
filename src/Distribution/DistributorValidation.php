<?php

declare(strict_types=1);

namespace SaniTube\Distribution;

use SaniTube\Foundation\Validation\ValidationResult;

/**
 * What a distributor said about a package it has not been given yet.
 *
 * Separate from {@see ValidationResult}, and both are
 * needed. SaniTube's own validation says whether the release is internally
 * coherent; this says whether *this particular distributor* will take it, and
 * every distributor's rules are stricter and different. A release can be
 * perfectly valid and still be refused for a territory restriction or a
 * genre code one store does not recognise.
 */
final readonly class DistributorValidation
{
    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $errors = [],
        public array $warnings = [],
    ) {}

    public function isAcceptable(): bool
    {
        return $this->errors === [];
    }
}
