<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Exceptions;

use RuntimeException;
use SaniTube\Ingestion\Enums\IngestionFailureCode;

/**
 * A failure that belongs to one item or one request, carrying the code a retry
 * policy needs rather than only a sentence a human reads.
 */
final class IngestionException extends RuntimeException
{
    /**
     * Named `failureCode` rather than `code`: Exception already has a `$code`,
     * it is an int, and shadowing it with an enum is the kind of collision
     * that surfaces as an unrelated type error somewhere else entirely.
     */
    public function __construct(
        string $message,
        public readonly IngestionFailureCode $failureCode = IngestionFailureCode::Unknown,
        ?string $reason = null,
    ) {
        parent::__construct($message);

        $this->reason = $reason ?? $failureCode->value;
    }

    /**
     * Why this was refused, for a caller that has to say so to a person.
     *
     * Separate from `failureCode`, which is a *persisted* column describing why
     * an item failed to import and which a retry policy branches on. Several
     * distinct refusals share `UNSUPPORTED_SOURCE` there — a blank prefix, a
     * managed prefix, a manifest supplied alongside a prefix — and collapsing
     * them into one is fine for a retry policy and useless on a screen, where
     * they need three different sentences. ING-002 needed to tell them apart;
     * adding cases to a populated enum would have been a migration for the
     * sake of a label.
     */
    public readonly string $reason;

    public static function unsupportedSource(string $source): self
    {
        return new self(
            sprintf('[%s] cannot be ingested yet. Declared so the column knows its range, not implemented.', $source),
            IngestionFailureCode::UnsupportedSource,
            'UNSUPPORTED_SOURCE',
        );
    }

    public static function emptyPrefix(): self
    {
        return new self(
            'A cloud import needs a prefix or an explicit list of objects. Importing an entire store '
                .'blindly is never what someone meant, and it is how a platform ingests its own output.',
            IngestionFailureCode::UnsupportedSource,
            'NOTHING_SELECTED',
        );
    }

    public static function managedPrefix(string $prefix): self
    {
        return new self(
            sprintf(
                'Refusing to import from [%s]: it holds objects SaniTube manages. Importing them would '
                    .'re-ingest the platform\'s own assets as though they were new material.',
                $prefix,
            ),
            IngestionFailureCode::UnsupportedSource,
            'MANAGED_PREFIX',
        );
    }

    public static function ambiguousSelection(): self
    {
        return new self(
            'A folder and a list of objects both say what to import, and there is no rule for which '
                .'one wins. This used to keep the folder and drop the list silently, which is how an '
                .'import quietly does something nobody asked for.',
            IngestionFailureCode::UnsupportedSource,
            'AMBIGUOUS_SELECTION',
        );
    }

    public static function manifestWithOtherSelection(): self
    {
        return new self(
            'A manifest already says what to import. Supplying a prefix or a list of references '
                .'beside it leaves two things naming the batch with no rule for which one wins.',
            IngestionFailureCode::UnsupportedSource,
            'MANIFEST_WITH_OTHER_SELECTION',
        );
    }

    public static function batchTooLarge(int $requested, int $limit): self
    {
        return new self(
            sprintf(
                'A batch of %d exceeds the limit of %d. Split it: a batch is a unit someone has to be '
                    .'able to inspect when it half-fails.',
                $requested,
                $limit,
            ),
            IngestionFailureCode::UnsupportedSource,
            'BATCH_TOO_LARGE',
        );
    }

    public static function nothingToImport(string $prefix): self
    {
        return new self(
            sprintf('Nothing to import under [%s].', $prefix),
            IngestionFailureCode::SourceUnreadable,
            'NOTHING_TO_IMPORT',
        );
    }

    public static function sourceUnreadable(string $reference): self
    {
        return new self(
            sprintf('The source [%s] could not be read.', $reference),
            IngestionFailureCode::SourceUnreadable,
            'SOURCE_UNREADABLE',
        );
    }

    public static function outsideInbox(string $reference, string $inbox): self
    {
        return new self(
            sprintf('A manual upload must refer to an object under [%s/]; [%s] does not.', $inbox, $reference),
            IngestionFailureCode::UnsupportedSource,
            'OUTSIDE_INBOX',
        );
    }
}
