<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Sources;

use SaniTube\Ingestion\Enums\IngestionFailureCode;
use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Exceptions\IngestionException;
use SaniTube\Ingestion\Sources\Contracts\IngestionSourceReader;
use SaniTube\Storage\Contracts\StorageProvider;
use SaniTube\Storage\Exceptions\StorageOperationFailed;
use SaniTube\Storage\StorageManager;

/**
 * Material a client uploaded and then asked to have imported.
 *
 * The bytes arrive through the ordinary storage pipeline into a reserved inbox
 * prefix, and the batch refers to them by key. That keeps the request that
 * creates a batch small — references, not payloads — which is the only way 900
 * files can be imported without any of them transiting an HTTP request twice.
 *
 * A reference outside the inbox is refused. Otherwise "import this object"
 * would be a way to name any object in the store, including a master.
 */
final readonly class ManualUploadReader implements IngestionSourceReader
{
    public function __construct(
        private StorageManager $storage,
        private ?string $provider = null,
    ) {}

    public function source(): IngestionSource
    {
        return IngestionSource::ManualUpload;
    }

    public function assertAcceptable(string $reference): void
    {
        $inbox = $this->inbox();
        $trimmed = trim($reference, '/');

        if (! str_starts_with($trimmed, $inbox.'/')) {
            throw IngestionException::outsideInbox($reference, $inbox);
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
            throw new IngestionException($exception->getMessage(), IngestionFailureCode::SourceUnreadable);
        }
    }

    public function originalFilename(string $reference): string
    {
        return basename($reference);
    }

    private function inbox(): string
    {
        return trim((string) config('ingestion.inbox_prefix', 'inbox'), '/');
    }

    private function store(): StorageProvider
    {
        return $this->provider === null
            ? $this->storage->default()
            : $this->storage->provider($this->provider);
    }
}
