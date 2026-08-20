<?php

declare(strict_types=1);

namespace SaniTube\Storage\Concerns;

use DateTimeInterface;
use Illuminate\Filesystem\FilesystemAdapter;
use SaniTube\Storage\Contracts\SupportsDirectUpload;
use SaniTube\Storage\Exceptions\StorageOperationFailed;
use SaniTube\Storage\PresignedUpload;
use SaniTube\Storage\Providers\FilesystemStorageProvider;
use Throwable;

/**
 * The object-storage half of {@see SupportsDirectUpload}.
 *
 * A trait rather than a method on {@see FilesystemStorageProvider}, because
 * every provider inherits from that one and the local disk must not acquire an
 * upload URL it cannot mint. Only the providers that declare the capability
 * pull this in, so "has the method" and "declares the interface" cannot drift
 * apart.
 */
trait MintsUploadUrls
{
    public function temporaryUploadUrl(string $key, DateTimeInterface $expiresAt): PresignedUpload
    {
        try {
            $disk = $this->disk;

            if (! $disk instanceof FilesystemAdapter) {
                // The provider claims a capability its disk does not have.
                // A configuration mistake, not something a caller can fix by
                // retrying.
                throw StorageOperationFailed::write($this->name, $key);
            }

            $signed = $disk->temporaryUploadUrl($key, $expiresAt);
        } catch (Throwable) {
            // A disk that cannot sign is a configuration problem, not a
            // caller error: the credentials are wrong, or the driver is not
            // the one the provider claims. Either way the browser must not be
            // handed something that will fail halfway through a two gigabyte
            // upload.
            throw StorageOperationFailed::write($this->name, $key);
        }

        /** @var array<string, string> $headers */
        $headers = $signed['headers'] ?? [];

        return new PresignedUpload(
            url: (string) ($signed['url'] ?? ''),
            headers: $headers,
            expiresAt: $expiresAt->format(DATE_ATOM),
        );
    }
}
