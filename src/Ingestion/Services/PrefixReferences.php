<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Services;

use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Exceptions\IngestionException;
use SaniTube\Ingestion\Sources\SourceReaderFactory;
use SaniTube\Storage\StorageManager;

/**
 * What "import everything under this folder" resolves to.
 *
 * One definition, because there are two callers — the service that starts a
 * batch and the planner that works out how many batches a catalogue needs —
 * and two definitions of *which objects a prefix means* would drift into a
 * planner that promises files the importer then refuses.
 *
 * **The order is stable, and nothing depends on it.** The listing is sorted
 * before it is returned so that batches are predictable — the same folder gives
 * the same first five hundred, which makes a half-finished import something an
 * operator can read. It is deliberately *not* how resumption works: what is
 * left to import is read from the items table, so a provider that re-lists in a
 * different order after objects were added still partitions the catalogue with
 * no overlap and no gap. An order that matters is an order that eventually
 * betrays you.
 */
final readonly class PrefixReferences
{
    public function __construct(
        private StorageManager $storage,
        private SourceReaderFactory $readers,
    ) {}

    /**
     * Object keys under a prefix, with the platform's own output excluded.
     *
     * @return list<string>
     *
     * @throws IngestionException when the prefix itself is not one the platform will read from
     */
    public function under(string $prefix, ?string $provider = null): array
    {
        $reader = $this->readers->for(IngestionSource::CloudImport, $provider);

        // The prefix is checked before the listing, not per key: pointing an
        // import at the staging area is a mistake to refuse outright rather
        // than to filter down to nothing and call empty.
        $reader->assertAcceptable($prefix);

        $store = $provider === null ? $this->storage->default() : $this->storage->provider($provider);

        $references = array_values(array_filter(
            $store->files($prefix),
            function (string $key) use ($reader): bool {
                try {
                    $reader->assertAcceptable($key);

                    return true;
                } catch (IngestionException) {
                    return false;
                }
            },
        ));

        sort($references);

        return $references;
    }
}
