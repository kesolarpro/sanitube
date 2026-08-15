<?php

declare(strict_types=1);

namespace SaniTube\Storage\Contracts;

use DateTimeInterface;
use SaniTube\Storage\StorageHealth;
use SaniTube\Storage\StoredObject;

/**
 * Object storage as SaniTube needs it, independent of any vendor.
 *
 * Nothing in the domain may talk to a bucket, an endpoint or the local disk
 * directly. Masters, derivatives, artwork and distribution exports are all
 * addressed by an opaque object key through this contract, so swapping a
 * provider is a configuration change and never a code change.
 *
 * Two methods carry more weight than the rest:
 *
 *   - {@see checksum()} reads the object back out of storage and hashes what
 *     is actually there. It is the difference between "we believe we wrote the
 *     right bytes" and "the right bytes are in the bucket".
 *   - {@see temporaryUrl()} is the only sanctioned way an audio asset reaches
 *     a browser or a distributor. There is no permanent-URL path for a master.
 */
interface StorageProvider
{
    /**
     * Configuration name of this provider.
     */
    public function name(): string;

    /**
     * Whether this provider can mint expiring URLs. Local disks generally
     * cannot, which is why playback must degrade gracefully rather than assume.
     */
    public function supportsTemporaryUrls(): bool;

    /**
     * Store raw contents (string or stream resource) at the given key.
     *
     * @param  array<string, mixed>  $options
     */
    public function put(string $key, mixed $contents, array $options = []): StoredObject;

    /**
     * Store an open stream without buffering it.
     *
     * This is the path every large upload takes: a 900 MB master must never
     * have to fit in PHP's memory, on a shared host least of all.
     *
     * @param  resource  $stream
     * @param  array<string, mixed>  $options
     */
    public function putStream(string $key, $stream, array $options = []): StoredObject;

    /**
     * Store a file already present on the local filesystem, streaming it.
     *
     * @param  array<string, mixed>  $options
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
     * Unix timestamp of the object's last write.
     *
     * Needed to tell an upload that is still in progress from one that was
     * abandoned; without it, cleanup could only guess.
     */
    public function lastModified(string $key): int;

    /**
     * Object keys under a prefix.
     *
     * Scoped to a prefix rather than offering a bucket-wide listing: the only
     * caller is the staging sweep, and a sweep that can see the whole bucket
     * is a sweep that can delete a master.
     *
     * @return list<string>
     */
    public function files(string $prefix = ''): array;

    /**
     * Hash of the object as it exists in storage, computed by streaming it.
     *
     * Deliberately not "the hash we calculated while uploading": that answers
     * a different question. This one can catch a truncated write, a silently
     * dropped multipart part, or an object that was replaced since.
     */
    public function checksum(string $key, string $algorithm = 'sha256'): string;

    /**
     * Relocate an object, server-side where the provider supports it.
     *
     * Used for the staging-to-canonical promotion, which must not pull a
     * master back down through PHP just to put it somewhere else.
     */
    public function move(string $from, string $to): StoredObject;

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

    /**
     * Probe the provider for real: write, read back, delete.
     *
     * Configuration that parses is not configuration that works. An install
     * must be able to find out that its credentials are read-only before a
     * master goes missing rather than after.
     */
    public function healthCheck(): StorageHealth;
}
