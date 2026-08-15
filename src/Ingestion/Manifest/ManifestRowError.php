<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Manifest;

/**
 * A manifest line that will not be imported, and why.
 *
 * Refused rows are collected rather than thrown, because a manifest of nine
 * hundred lines with three bad ones must import eight hundred and ninety
 * seven. The three are reported by line number so they can be fixed and the
 * manifest re-run — which is safe, because re-running an import is idempotent
 * on the ingestion key.
 *
 * The code exists so that something other than a human can react: a
 * `MALFORMED_ISRC` needs the spreadsheet corrected, a `DUPLICATE_REFERENCE`
 * needs a decision about which line was meant.
 */
final readonly class ManifestRowError
{
    public const MISSING_REFERENCE = 'MISSING_REFERENCE';

    public const DUPLICATE_REFERENCE = 'DUPLICATE_REFERENCE';

    public const DUPLICATE_ISRC = 'DUPLICATE_ISRC';

    public const MALFORMED_IDENTIFIER = 'MALFORMED_IDENTIFIER';

    public const MALFORMED_NUMBER = 'MALFORMED_NUMBER';

    public const UNACCEPTABLE_REFERENCE = 'UNACCEPTABLE_REFERENCE';

    public function __construct(
        public int $line,
        public string $code,
        public string $message,
        public ?string $reference = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'line' => $this->line,
            'code' => $this->code,
            'message' => $this->message,
            'reference' => $this->reference,
        ];
    }
}
