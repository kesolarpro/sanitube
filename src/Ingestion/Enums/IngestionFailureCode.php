<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Enums;

/**
 * Why an item did not import, in a form something other than a human can act
 * on.
 *
 * A message explains; a code lets a retry policy decide. `PROVIDER_UNAVAILABLE`
 * is worth retrying and `CHECKSUM_MISMATCH` is not, and no amount of parsing
 * an English sentence tells you which is which.
 */
enum IngestionFailureCode: string
{
    case SourceUnreadable = 'SOURCE_UNREADABLE';
    case ProviderUnavailable = 'PROVIDER_UNAVAILABLE';
    case StorageFailure = 'STORAGE_FAILURE';
    case ChecksumMismatch = 'CHECKSUM_MISMATCH';
    case TooLarge = 'TOO_LARGE';
    case EmptySource = 'EMPTY_SOURCE';
    case AlreadyInFlight = 'ALREADY_IN_FLIGHT';
    case UnsupportedSource = 'UNSUPPORTED_SOURCE';
    case VerificationFailed = 'VERIFICATION_FAILED';
    case Unknown = 'UNKNOWN';

    /**
     * Whether trying again could plausibly produce a different answer.
     */
    public function isTransient(): bool
    {
        return match ($this) {
            self::ProviderUnavailable, self::StorageFailure, self::Unknown => true,
            default => false,
        };
    }
}
