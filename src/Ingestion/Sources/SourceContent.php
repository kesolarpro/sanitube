<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Sources;

/**
 * A source object, spooled locally, with its bytes identified.
 *
 * The identity is a SHA-256 **of the bytes that were actually read**, not of
 * anything the source said about itself. That is the whole point of
 * BULK-001c: an object key is a name, and a name can be pointed at different
 * content tomorrow.
 *
 * `path` is a temporary file this process owns and must delete. It exists so
 * the source is read exactly once: hashing a stream consumes it, and asking a
 * remote provider for the same object twice is a second download of a master.
 */
final readonly class SourceContent
{
    public function __construct(
        public string $path,
        public string $sha256,
        public int $byteSize,
    ) {}

    public function discard(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
