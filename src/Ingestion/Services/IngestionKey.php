<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Services;

use SaniTube\Ingestion\Enums\IngestionSource;

/**
 * The identity of a request to import something.
 *
 * Not the identity of the *bytes* — that is the SHA-256, and it cannot be
 * known until the bytes have been stored and read back. This is the identity
 * of the intent, and it has to exist before any work starts, because the whole
 * point is to recognise a second submission of the same intent before it
 * becomes a second upload.
 *
 * Two rules shape it:
 *
 *   - **A filename is never an identity.** Everyone's file is called
 *     `master.wav`. For a source with a real external reference — an object
 *     key, an ID at a counterparty — that reference plus the source name is
 *     the key. For anything else the caller supplies an explicit token.
 *   - **It is hashed.** A cloud object reference can be a thousand characters,
 *     and the column is part of a unique index; under utf8mb4 the readable
 *     form would not fit. The digest is fixed-width and the readable form
 *     lives beside it in `source_reference`, where it is not indexed and can
 *     be as long as it likes.
 *
 * The SHA-256 of the *content* still does its own job later: it is what makes
 * an upload of the same recording under a different name resolve to a
 * duplicate rather than a second master.
 */
final readonly class IngestionKey
{
    public static function for(IngestionSource $source, string $reference): string
    {
        return hash('sha256', $source->value.'|'.trim($reference));
    }

    /**
     * A key for material with no prior identity, from a token the caller
     * chose.
     *
     * Used when a client wants a retry of its own submission to be recognised
     * as the same submission — the client is the only party that knows the two
     * requests were meant to be one.
     */
    public static function fromClientToken(IngestionSource $source, string $token): string
    {
        return hash('sha256', $source->value.'|token|'.trim($token));
    }
}
