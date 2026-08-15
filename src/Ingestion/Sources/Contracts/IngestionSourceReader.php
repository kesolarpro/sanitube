<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Sources\Contracts;

use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Exceptions\IngestionException;

/**
 * How one kind of source hands its bytes over.
 *
 * Deliberately narrow. A reader opens a stream and says what the thing was
 * called; it does not decide what the object key will be, whether the file is
 * acceptable, or what it hashes to. All of that belongs to the asset workflow,
 * which already does it identically for every source — and a reader that could
 * influence any of it would be a second, weaker copy of those rules.
 */
interface IngestionSourceReader
{
    public function source(): IngestionSource;

    /**
     * Refuse a reference this source will not accept, before any work starts.
     *
     * Called when the batch is created rather than when the job runs, so that
     * a request naming five hundred unacceptable objects fails once, in the
     * response, instead of five hundred times in a queue.
     *
     * @throws IngestionException
     */
    public function assertAcceptable(string $reference): void;

    /**
     * @return resource
     */
    public function open(string $reference);

    /**
     * What a human would recognise this by. A label, never an identity —
     * the object key is derived from the asset's UUID.
     */
    public function originalFilename(string $reference): string;
}
