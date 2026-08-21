<?php

declare(strict_types=1);

namespace SaniTube\Storage\Providers;

use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use SaniTube\Storage\Contracts\StorageProvider;
use SaniTube\Storage\Exceptions\StorageOperationFailed;
use SaniTube\Storage\Exceptions\TemporaryUrlsUnsupported;
use SaniTube\Storage\ObjectPrefix;
use SaniTube\Storage\StorageHealth;
use SaniTube\Storage\StoredObject;
use Throwable;

/**
 * Flysystem-backed implementation shared by every concrete provider.
 *
 * All of SaniTube's storage targets are Flysystem adapters, so the mechanics
 * live here once and the subclasses only declare what makes them different.
 */
class FilesystemStorageProvider implements StorageProvider
{
    /**
     * Where health probes are written. Isolated under a reserved prefix so a
     * probe can never collide with an asset, and so a sweep of this prefix is
     * always safe.
     */
    protected const HEALTH_PREFIX = '.sanitube-health';

    /** Chunk size for streaming reads, in bytes. */
    protected const CHUNK_BYTES = 1_048_576;

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

    /**
     * @param  array<string, mixed>  $options
     */
    public function put(string $key, mixed $contents, array $options = []): StoredObject
    {
        if ($this->disk->put($key, $contents, $options) === false) {
            throw StorageOperationFailed::write($this->name, $key);
        }

        return $this->describe($key);
    }

    /**
     * @param  resource  $stream
     * @param  array<string, mixed>  $options
     */
    public function putStream(string $key, $stream, array $options = []): StoredObject
    {
        if ($this->disk->writeStream($key, $stream, $options) === false) {
            throw StorageOperationFailed::write($this->name, $key);
        }

        return $this->describe($key);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function putFile(string $key, string $localPath, array $options = []): StoredObject
    {
        $stream = @fopen($localPath, 'rb');

        if ($stream === false) {
            throw StorageOperationFailed::unreadableSource($localPath);
        }

        try {
            return $this->putStream($key, $stream, $options);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
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

    public function lastModified(string $key): int
    {
        return $this->disk->lastModified($key);
    }

    public function files(string $prefix = ''): array
    {
        $scope = ObjectPrefix::normalise($prefix);

        $keys = array_map(strval(...), $this->disk->allFiles(rtrim($scope, '/')));

        // Filtered against the scope the caller asked for, not merely handed
        // to the adapter and trusted. Adapters differ in how they treat a
        // prefix that is also a directory name, and the caller must never be
        // the first layer that checks.
        return array_values(array_filter(
            $keys,
            static fn (string $key): bool => ObjectPrefix::contains($scope, $key),
        ));
    }

    public function checksum(string $key, string $algorithm = 'sha256'): string
    {
        $stream = $this->readStream($key);

        try {
            $context = hash_init($algorithm);

            // Streamed rather than read whole: the objects this matters for
            // are masters, and a master does not fit in a shared host's
            // memory limit.
            while (! feof($stream)) {
                $chunk = fread($stream, self::CHUNK_BYTES);

                if ($chunk === false) {
                    throw StorageOperationFailed::read($this->name, $key);
                }

                hash_update($context, $chunk);
            }

            return hash_final($context);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function move(string $from, string $to): StoredObject
    {
        if ($this->disk->move($from, $to) === false) {
            throw StorageOperationFailed::move($this->name, $from, $to);
        }

        return $this->describe($to);
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

    public function healthCheck(): StorageHealth
    {
        $key = sprintf('%s/probe-%s.txt', self::HEALTH_PREFIX, Str::random(16));
        $payload = 'sanitube-storage-probe';
        $checks = ['write' => false, 'read' => false, 'delete' => false];

        try {
            $this->put($key, $payload);
            $checks['write'] = true;

            // Read back rather than trusting the write. An endpoint that
            // accepts writes and serves stale or empty objects is a real
            // failure mode, and it is invisible to a write-only probe.
            $checks['read'] = $this->get($key) === $payload;

            $checks['delete'] = $this->delete($key);
        } catch (Throwable $exception) {
            $this->forget($key);

            return StorageHealth::failing(
                $this->name,
                $checks,
                $this->supportsTemporaryUrls(),
                $exception->getMessage(),
            );
        }

        if (in_array(false, $checks, true)) {
            $this->forget($key);

            return StorageHealth::failing(
                $this->name,
                $checks,
                $this->supportsTemporaryUrls(),
                'The provider answered, but not correctly. Check the credentials\' permissions.',
            );
        }

        return StorageHealth::passing($this->name, $checks, $this->supportsTemporaryUrls());
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

    /**
     * Best-effort cleanup of a probe object. A health check that fails must not
     * also leave litter behind, but it must report the original failure rather
     * than one that happened while tidying up.
     */
    private function forget(string $key): void
    {
        try {
            $this->disk->delete($key);
        } catch (Throwable) {
            // Reported by the probe result itself.
        }
    }
}
