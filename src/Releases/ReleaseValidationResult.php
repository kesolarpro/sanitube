<?php

declare(strict_types=1);

namespace SaniTube\Releases;

/**
 * What is wrong with a release, separated into what blocks it and what does not.
 *
 * The separation is the point. A release with no cover cannot be delivered —
 * every store rejects it — while a release with no catalogue number is merely
 * incomplete, and a validator that reported both as "errors" would train its
 * reader to ignore the list. Warnings are things a label will probably want to
 * fix and can legitimately ship without.
 */
final readonly class ReleaseValidationResult
{
    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $errors = [],
        public array $warnings = [],
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->isValid(),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }
}
