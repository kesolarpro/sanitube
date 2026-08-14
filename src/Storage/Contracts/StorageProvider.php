<?php

declare(strict_types=1);

namespace SaniTube\Storage\Contracts;

use DateTimeInterface;
use SaniTube\Storage\StoredObject;

/**
 * Object storage as SaniTube needs it, independent of any vendor.
 *
 * Nothing in the domain may talk to S3, R2, B2 or the local disk directly.
 * Masters, derivatives, artwork and distribution exports are all addressed by
 * an opaque object key through this contract, so swapping a provider is a
 * configuration change and never a code change.
 */
interface StorageProvider
{
    /**
     * Configuration name of this provider, e.g. "s3", "r2", "local".
     */
    public function name(): string;

    /**
     * Whether this provider can mint expiring URLs. Local disks generally
     * cannot, which is why playback must degrade gracefully rather than assume.
     */
    public function supportsTemporaryUrls(): bool;

    /**
     * Store raw contents (string or stream resource) at the given key.
     */
    public function put(string $key, mixed $contents, array $options = []): StoredObject;

    /**
     * Store a file already present on the local filesystem, streaming it so
     * that a 900-track import never has to fit a master in memory.
     */
    public function putFile(string $key, string $localPath, array $options = []): StoredObject;

    public function get(string $key): string;

    /**
     * @return resource
     */
    public function readStream(string $key);

    public function exists(string $key): bool;

    public function delete(string $key): bool;

    public function size(string $key): int;

    /**
     * Permanent URL. Only meaningful for objects deliberately made public;
     * masters must never be exposed this way.
     */
    public function url(string $key): string;

    /**
     * Expiring URL for private objects — the only supported way to hand an
     * audio asset to a browser or a distributor.
     */
    public function temporaryUrl(string $key, DateTimeInterface $expiresAt): string;
}
