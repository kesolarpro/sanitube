<?php

declare(strict_types=1);

namespace SaniTube\Storage\Providers;

use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use SaniTube\Storage\Contracts\StorageProvider;
use SaniTube\Storage\Exceptions\StorageOperationFailed;
use SaniTube\Storage\Exceptions\TemporaryUrlsUnsupported;
use SaniTube\Storage\StoredObject;
use Throwable;

/**
 * Flysystem-backed implementation shared by every concrete provider.
 *
 * All of SaniTube's storage targets — S3, Cloudflare R2, Backblaze B2 and the
 * local disk — are Flysystem adapters, so the mechanics live here once and the
 * subclasses only declare what makes them different.
 */
class FilesystemStorageProvider implements StorageProvider
{
    public function __construct(
        protected readonly Filesystem $disk,
        protected readonly string $name,
        protected readonly bool $temporaryUrls = true,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function supportsTemporaryUrls(): bool
    {
        return $this->temporaryUrls;
    }

    public function put(string $key, mixed $contents, array $options = []): StoredObject
    {
        if ($this->disk->put($key, $contents, $options) === false) {
            throw StorageOperationFailed::write($this->name, $key);
        }

        return $this->describe($key);
    }

    public function putFile(string $key, string $localPath, array $options = []): StoredObject
    {
        $stream = @fopen($localPath, 'rb');

        if ($stream === false) {
            throw StorageOperationFailed::unreadableSource($localPath);
        }

        try {
            if ($this->disk->put($key, $stream, $options) === false) {
                throw StorageOperationFailed::write($this->name, $key);
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $this->describe($key);
    }

    public function get(string $key): string
    {
        $contents = $this->disk->get($key);

        return $contents ?? throw StorageOperationFailed::read($this->name, $key);
    }

    public function readStream(string $key)
    {
        $stream = $this->disk->readStream($key);

        return is_resource($stream) ? $stream : throw StorageOperationFailed::read($this->name, $key);
    }

    public function exists(string $key): bool
    {
        return $this->disk->exists($key);
    }

    public function delete(string $key): bool
    {
        return $this->disk->delete($key);
    }

    public function size(string $key): int
    {
        return $this->disk->size($key);
    }

    public function url(string $key): string
    {
        try {
            return $this->disk->url($key);
        } catch (Throwable $exception) {
            throw StorageOperationFailed::noPublicUrl($this->name, $key, $exception);
        }
    }

    public function temporaryUrl(string $key, DateTimeInterface $expiresAt): string
    {
        if (! $this->supportsTemporaryUrls()) {
            throw TemporaryUrlsUnsupported::for($this->name);
        }

        try {
            return $this->disk->temporaryUrl($key, $expiresAt);
        } catch (Throwable $exception) {
            throw TemporaryUrlsUnsupported::for($this->name, $exception);
        }
    }

    protected function describe(string $key): StoredObject
    {
        return new StoredObject(
            provider: $this->name,
            key: $key,
            size: $this->disk->size($key),
            mimeType: $this->detectMimeType($key),
        );
    }

    protected function detectMimeType(string $key): ?string
    {
        try {
            $mimeType = $this->disk->mimeType($key);
        } catch (Throwable) {
            return null;
        }

        return is_string($mimeType) && $mimeType !== '' ? $mimeType : null;
    }
}
