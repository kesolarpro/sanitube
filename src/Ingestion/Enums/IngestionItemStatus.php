<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Enums;

/**
 * What became of one source in a batch.
 *
 * The terminal set is what the idempotency guarantee is built on: an item is
 * "in flight" precisely while it is PENDING or PROCESSING, and the database
 * refuses a second in-flight item for the same ingestion key.
 *
 * `SKIPPED` and `DUPLICATE` are separate on purpose. Skipped means the work was
 * not attempted — something else was already doing it. Duplicate means the work
 * was done and the bytes turned out to be held already. Both are ordinary
 * outcomes; neither is a failure.
 */
enum IngestionItemStatus: string
{
    case Pending = 'PENDING';
    case Processing = 'PROCESSING';
    case Imported = 'IMPORTED';
    case Duplicate = 'DUPLICATE';
    case NeedsReview = 'NEEDS_REVIEW';
    case Failed = 'FAILED';
    case Skipped = 'SKIPPED';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Pending, self::Processing => false,
            default => true,
        };
    }

    /**
     * Whether this outcome should make the whole batch report errors.
     */
    public function isError(): bool
    {
        return $this === self::Failed;
    }
}
