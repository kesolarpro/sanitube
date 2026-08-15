<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Sources;

use SaniTube\Assets\Storage\AssetObjectKeys;
use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Exceptions\IngestionException;
use SaniTube\Ingestion\Sources\Contracts\IngestionSourceReader;
use SaniTube\Storage\StorageManager;

/**
 * Picks the reader for a source, and refuses the ones that do not exist yet.
 *
 * `GENERATED` and `LEGACY_IMPORT` are valid enum cases with no implementation.
 * They fail here, loudly and by name, rather than through a null that surfaces
 * three frames away as something less obviously about the source.
 */
final readonly class SourceReaderFactory
{
    public function __construct(
        private StorageManager $storage,
        private AssetObjectKeys $keys,
    ) {}

    public function for(IngestionSource $source, ?string $provider = null): IngestionSourceReader
    {
        return match ($source) {
            IngestionSource::CloudImport => new CloudImportReader($this->storage, $this->keys, $provider),
            IngestionSource::ManualUpload => new ManualUploadReader($this->storage, $provider),
            default => throw IngestionException::unsupportedSource($source->value),
        };
    }
}
