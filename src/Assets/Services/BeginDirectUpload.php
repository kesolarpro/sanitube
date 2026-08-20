<?php

declare(strict_types=1);

namespace SaniTube\Assets\Services;

use DateTimeImmutable;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Exceptions\AssetStorageException;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Storage\AssetObjectKeys;
use SaniTube\Storage\Contracts\SupportsDirectUpload;
use SaniTube\Storage\PresignedUpload;
use SaniTube\Storage\StorageManager;

/**
 * Authorising a browser to write one object, once.
 *
 * A master is routinely hundreds of megabytes. Pushing that through PHP on a
 * shared cPanel account means `upload_max_filesize`, `post_max_size`,
 * `memory_limit` and `max_execution_time` all have to be generous at the same
 * time — and on the hosts this platform targets, at least one of them never is.
 * So the browser writes to object storage directly and the application stays
 * out of the data path.
 *
 * **What the application does not give up is authority.** It registers the
 * asset first, which decides both the staging key and the canonical key from
 * the asset's own uuid; the URL it mints can write to exactly one of those, for
 * a few minutes. The browser chooses the bytes. It chooses nothing else — not
 * where they go, not what they are called, not whether they were acceptable.
 *
 * Nothing is trusted from the completion signal either. See
 * {@see AssetStorageService::finalizeDirectUpload()}: the size, the checksum
 * and the media type are all measured from the stored object afterwards.
 */
final readonly class BeginDirectUpload
{
    /**
     * Long enough for a slow connection to start, short enough that a URL
     * found in a log tomorrow is worthless.
     *
     * It bounds the *start* of the upload, not its duration: a signature is
     * checked when the request begins, so a two-gigabyte PUT that starts
     * inside the window may finish well outside it.
     */
    public const TTL_SECONDS = 900;

    public function __construct(
        private StorageManager $storage,
        private AssetStorageService $assets,
        private AssetObjectKeys $keys,
    ) {}

    /**
     * @return array{asset: Asset, upload: PresignedUpload}
     *
     * @throws AssetStorageException when this installation's storage cannot accept direct uploads
     */
    public function for(AssetKind $kind, string $originalFilename, ?DateTimeImmutable $now = null): array
    {
        $provider = $this->storage->default();

        if (! $provider instanceof SupportsDirectUpload) {
            // The local disk, typically. Not a failure of the request: this
            // installation simply has no object storage to write into, and the
            // ordinary upload path still works. The interface asks before
            // offering the control, and the server refuses regardless of what
            // was offered.
            throw AssetStorageException::directUploadUnsupported($provider->name());
        }

        // Registered before the URL exists, so the key the URL can write to was
        // decided here. Doing it the other way round would mean minting a
        // capability for a location nothing had claimed.
        $asset = $this->assets->register($kind, $originalFilename);

        $expiresAt = ($now ?? new DateTimeImmutable)->modify('+'.self::TTL_SECONDS.' seconds');

        $upload = $provider->temporaryUploadUrl(
            $this->keys->staging($asset->uuid, $asset->original_filename),
            $expiresAt,
        );

        return ['asset' => $asset, 'upload' => $upload];
    }
}
