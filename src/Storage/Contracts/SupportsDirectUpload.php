<?php

declare(strict_types=1);

namespace SaniTube\Storage\Contracts;

use DateTimeInterface;
use SaniTube\Storage\PresignedUpload;

/**
 * A provider that can let a browser write straight into storage.
 *
 * **A capability, not a method on every provider.** The same shape DIST-001-H1
 * settled for submission lookup: a provider either can do this or it cannot,
 * and asking the type is honest where a method returning null or throwing
 * would be a promise nobody kept. The local disk cannot mint an upload URL and
 * must not pretend to — an install on a shared account without object storage
 * keeps the ordinary upload path, and the interface is how the application
 * knows which one it is looking at.
 *
 * The reason this exists at all is size. A master is routinely hundreds of
 * megabytes and may be two gigabytes; pushing that through PHP on a shared
 * cPanel account means the memory limit, the execution timeout and the request
 * body limit all have to be wrong at once for it to work. Letting the browser
 * write to object storage directly removes the application from the data path
 * entirely.
 *
 * **What it does not remove is the application's authority.** The URL this
 * mints writes to a staging key the application chose, expires in minutes, and
 * proves nothing about what landed there. Everything that matters — the size,
 * the checksum, the media type, whether the object exists at all — is measured
 * by the server afterwards, from the object itself. The client is never
 * authoritative for any of it.
 */
interface SupportsDirectUpload
{
    /**
     * An expiring capability to PUT one object at one key.
     *
     * Deliberately narrow: one key, one short window. Not a token the browser
     * can reuse, not a prefix it can write anywhere under.
     *
     * **It carries no size limit.** S3-compatible presigned PUTs cannot
     * express one, so the cap is enforced where it can be — after the fact, by
     * measuring the stored object and discarding it if it is too large. The
     * staging lifecycle policy is the backstop for whatever is abandoned.
     */
    public function temporaryUploadUrl(string $key, DateTimeInterface $expiresAt): PresignedUpload;
}
