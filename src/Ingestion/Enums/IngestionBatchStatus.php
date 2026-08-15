<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Enums;

/**
 * How an import operation ended, or that it has not.
 *
 * `COMPLETED_WITH_ERRORS` is deliberately distinct from both `COMPLETED` and
 * `FAILED`. Importing 900 files and losing four of them is neither a success
 * nor a failure, and collapsing it into either is how the four go unnoticed.
 */
enum IngestionBatchStatus: string
{
    case Pending = 'PENDING';
    case Processing = 'PROCESSING';
    case Completed = 'COMPLETED';
    case CompletedWithErrors = 'COMPLETED_WITH_ERRORS';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Pending, self::Processing => false,
            default => true,
        };
    }
}
