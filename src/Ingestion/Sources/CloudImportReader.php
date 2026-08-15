<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Sources;

use SaniTube\Assets\Storage\AssetObjectKeys;
use SaniTube\Ingestion\Enums\IngestionFailureCode;
use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Exceptions\IngestionException;
use SaniTube\Ingestion\Sources\Contracts\IngestionSourceReader;
use SaniTube\Storage\Contracts\StorageProvider;
use SaniTube\Storage\Exceptions\StorageOperationFailed;
use SaniTube\Storage\StorageManager;

/**
 * Material already sitting in configured storage.
 *
 * The rule that matters here is what it refuses. A cloud import may not be
 * pointed at the prefixes SaniTube writes to itself: doing so would re-ingest
 * every master as new material, and running it twice would produce a
 * generation of duplicates of the duplicates. That is not a hypothetical — it
 * is the obvious thing to try when someone wants "import everything".
 */
final readonly class CloudImportReader implements IngestionSourceReader
{
    public function __construct(
        private StorageManager $storage,
        private AssetObjectKeys $keys,
        private ?string $provider = null,
    ) {}

    public function source(): IngestionSource
    {
        return IngestionSource::CloudImport;
    }

    public function assertAcceptable(string $reference): void
    {
        $trimmed = trim($reference, '/');

        if ($trimmed === '') {
            throw IngestionException::emptyPrefix();
        }

        foreach ($this->keys->managedPrefixes() as $managed) {
            if ($trimmed === $managed || str_starts_with($trimmed, $managed.'/')) {
                throw IngestionException::managedPrefix($managed);
            }
        }
    }

    /**
     * @return resource
     */
    public function open(string $reference)
    {
        $this->assertAcceptable($reference);

        try {
            return $this->store()->readStream($reference);
        } catch (StorageOperationFailed $exception) {
            throw new IngestionException(
                $exception->getMessage(),
                IngestionFailureCode::SourceUnreadable,
            );
        }
    }

    public function originalFilename(string $reference): string
    {
        return basename($reference);
    }

    private function store(): StorageProvider
    {
        return $this->provider === null
            ? $this->storage->default()
            : $this->storage->provider($this->provider);
    }
}
