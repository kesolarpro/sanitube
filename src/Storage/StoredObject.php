<?php

declare(strict_types=1);

namespace SaniTube\Storage;

/**
 * The result of writing an object to storage.
 *
 * Intentionally minimal: it describes the *object*, not the asset. Asset
 * identity, checksums-of-record and provenance belong to the Asset Registry.
 */
final readonly class StoredObject
{
    public function __construct(
        public string $provider,
        public string $key,
        public int $size,
        public ?string $mimeType = null,
    ) {}

    /**
     * @return array{provider: string, key: string, size: int, mime_type: string|null}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'key' => $this->key,
            'size' => $this->size,
            'mime_type' => $this->mimeType,
        ];
    }
}
