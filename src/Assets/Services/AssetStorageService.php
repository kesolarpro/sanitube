<?php

declare(strict_types=1);

namespace SaniTube\Assets\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Exceptions\AssetStorageException;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Storage\AssetObjectKeys;
use SaniTube\Assets\Storage\ByteCapFilter;
use SaniTube\Assets\Storage\OriginalFilename;
use SaniTube\Storage\Contracts\StorageProvider;
use SaniTube\Storage\StorageManager;
use Throwable;

/**
 * The only way bytes become an asset.
 *
 * Domain code never touches a disk or a filesystem facade; it comes
 * here, and this class talks to a {@see StorageProvider}. That is what makes
 * changing providers a configuration change.
 *
 * The workflow is deliberately staged:
 *
 *     register()  →  PENDING, a reserved key and nothing written
 *     store()     →  staging upload
 *                 →  read back: size, checksum, sniffed type
 *                 →  promote to the canonical key
 *                 →  STORED
 *
 * The read-back is the part worth defending. Hashing bytes as they go past
 * answers "did we send the right thing"; hashing the object afterwards answers
 * "is the right thing in storage", and only the second question matters when a
 * master is missing a year later. It costs one extra read of the staging
 * object and it catches truncated writes, dropped multipart parts and
 * endpoints that accept a write and store nothing.
 *
 * **There is no transaction across MySQL and object storage, and none is
 * claimed.** The two can disagree, so the design makes every disagreement
 * survivable rather than pretending it cannot happen:
 *
 *   - bytes written, database update failed → the asset stays PENDING; the
 *     object sits at its deterministic canonical key and the next attempt
 *     addresses exactly the same key, so a retry converges instead of
 *     accumulating.
 *   - upload failed part-way → whatever landed is in staging, is never
 *     promoted, and is swept by {@see StagingJanitor}.
 *   - the same upload runs twice → the second call finds matching bytes
 *     already stored and returns the asset unchanged.
 *
 * See docs/adr/ADR-0012-asset-storage-compensation.md.
 */
final readonly class AssetStorageService
{
    public function __construct(
        private StorageManager $storage,
        private AssetObjectKeys $keys,
    ) {}

    /**
     * Reserve an asset and the address its bytes will occupy.
     *
     * Nothing is written to storage. The row exists so that an upload which
     * dies half-way still leaves a trace of what was being attempted — an
     * upload nobody recorded is an upload nobody can clean up.
     */
    public function register(
        AssetKind $kind,
        string $originalFilename,
        ?Asset $parent = null,
        ?string $provider = null,
    ): Asset {
        // Generated here rather than left to the model, because the object key
        // is derived from it and the key has to exist before the row does.
        $uuid = (string) Str::uuid7();
        $providerName = $provider ?? $this->storage->defaultName();

        return Asset::query()->create([
            'uuid' => $uuid,
            'kind' => $kind,
            'parent_asset_id' => $parent?->id,
            'disk' => $providerName,
            'path' => $this->keys->canonical($kind, $uuid, $originalFilename),
            'original_filename' => OriginalFilename::sanitise($originalFilename),
            'status' => AssetStatus::Pending,
        ]);
    }

    /**
     * Take in the bytes for a registered asset.
     *
     * @param  resource  $stream
     * @param  string|null  $expectedSha256  when the caller already knows what the file should hash to,
     *                                       a mismatch discards the upload rather than recording it
     */
    public function store(
        Asset $asset,
        $stream,
        ?string $expectedSha256 = null,
        ?int $expectedByteSize = null,
    ): Asset {
        if ($settled = $this->settleIdempotently($asset, $expectedSha256)) {
            return $settled;
        }

        if ($asset->status !== AssetStatus::Pending) {
            throw AssetStorageException::notPending($asset->status);
        }

        $provider = $this->providerFor($asset);
        $limit = $this->limitFor($asset->kind);
        $stagingKey = $this->keys->staging($asset->uuid, $asset->original_filename);

        ByteCapFilter::apply($stream, $limit);

        $provider->putStream($stagingKey, $stream);

        try {
            $size = $provider->size($stagingKey);

            // The cap yields at most limit + 1 bytes, so an object of exactly
            // that size is the one that was still going when it was cut off.
            if ($size > $limit) {
                throw AssetStorageException::tooLarge($asset->kind->value, $limit);
            }

            if ($size === 0) {
                throw AssetStorageException::empty($stagingKey);
            }

            if ($expectedByteSize !== null && $expectedByteSize !== $size) {
                throw AssetStorageException::sizeMismatch($expectedByteSize, $size);
            }

            $sha256 = $provider->checksum($stagingKey);

            if ($expectedSha256 !== null && ! hash_equals(strtolower($expectedSha256), $sha256)) {
                throw AssetStorageException::checksumMismatch(strtolower($expectedSha256), $sha256);
            }

            $mimeType = $this->sniffMimeType($provider, $stagingKey);
        } catch (Throwable $exception) {
            // Nothing rejected is left where it could later be mistaken for an
            // asset. The sweep would eventually catch it; not leaving it is
            // better than relying on that.
            $this->discard($provider, $stagingKey);

            throw $exception;
        }

        $duplicateOf = $this->findDuplicate($asset, $sha256);

        $provider->move($stagingKey, $asset->path);

        // Past this point the bytes are canonical. If the database write fails
        // the asset stays PENDING and the object stays where a retry will
        // overwrite it — wrong, but not silently wrong, and self-correcting.
        DB::transaction(function () use ($asset, $sha256, $size, $mimeType, $duplicateOf): void {
            $asset->forceFill([
                'sha256' => $sha256,
                'byte_size' => $size,
                'mime_type' => $mimeType,
                'duplicate_of_asset_id' => $duplicateOf?->id,
                'status' => AssetStatus::Stored,
                'stored_at' => Carbon::now(),
            ])->save();
        });

        return $asset->refresh();
    }

    /**
     * Take in the bytes of a local file.
     */
    public function storeFile(
        Asset $asset,
        string $localPath,
        ?string $expectedSha256 = null,
        ?int $expectedByteSize = null,
    ): Asset {
        $stream = @fopen($localPath, 'rb');

        if ($stream === false) {
            throw AssetStorageException::empty($localPath);
        }

        try {
            return $this->store($asset, $stream, $expectedSha256, $expectedByteSize);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * @return resource
     */
    public function readStream(Asset $asset)
    {
        return $this->providerFor($asset)->readStream($asset->path);
    }

    /**
     * An expiring link to the object.
     *
     * The only sanctioned way an asset reaches a browser or a distributor.
     * There is no method here that returns a permanent URL, for any kind: a
     * master with a durable address is a master that leaks.
     */
    public function temporaryUrl(Asset $asset, ?int $ttlSeconds = null): string
    {
        $ttl = $ttlSeconds ?? (int) config('storage.temporary_url_ttl', 900);

        return $this->providerFor($asset)->temporaryUrl($asset->path, Carbon::now()->addSeconds($ttl));
    }

    public function providerFor(Asset $asset): StorageProvider
    {
        return $this->storage->provider($asset->disk);
    }

    public function limitFor(AssetKind $kind): int
    {
        $limits = (array) config('assets.max_upload_bytes', []);
        $limit = $limits[$kind->value] ?? null;

        return is_int($limit) && $limit > 0 ? $limit : 2 * 1024 * 1024 * 1024;
    }

    /**
     * A retry of an upload that already succeeded.
     *
     * Returns the asset untouched when the bytes in storage are the ones being
     * offered, and refuses when they are not. Retrying an import must not be
     * an error, and must not be a way to replace a master either.
     */
    private function settleIdempotently(Asset $asset, ?string $expectedSha256): ?Asset
    {
        if (! $asset->status->isImmutable()) {
            return null;
        }

        if ($expectedSha256 !== null && ! hash_equals(strtolower($expectedSha256), (string) $asset->sha256)) {
            throw AssetStorageException::contentMismatch((string) $asset->sha256);
        }

        return $asset;
    }

    /**
     * An asset already holding these exact bytes.
     *
     * Recorded, never rejected: the same master legitimately arrives twice.
     * Only assets whose bytes are known to exist are considered — a PENDING
     * row has no checksum to match, and a QUARANTINED one is not something to
     * point new work at.
     */
    private function findDuplicate(Asset $asset, string $sha256): ?Asset
    {
        if (! (bool) config('assets.duplicates.detect', true)) {
            return null;
        }

        return Asset::query()
            ->where('sha256', $sha256)
            ->whereIn('status', [AssetStatus::Stored->value, AssetStatus::Verified->value])
            ->whereKeyNot($asset->getKey())
            ->orderBy('id')
            ->first();
    }

    /**
     * What the bytes are, as opposed to what the upload said they were.
     *
     * The declared content type arrives from the client and is never stored.
     * Only the head of the object is read: file signatures live in the first
     * few bytes, and a master should not be pulled through PHP to identify it.
     */
    private function sniffMimeType(StorageProvider $provider, string $key): ?string
    {
        $stream = $provider->readStream($key);

        try {
            $head = fread($stream, 4096);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($head === false || $head === '') {
            return null;
        }

        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($head);

        return is_string($detected) && $detected !== '' ? $detected : null;
    }

    private function discard(StorageProvider $provider, string $key): void
    {
        try {
            $provider->delete($key);
        } catch (Throwable) {
            // The staging sweep is the backstop; the original failure is what
            // the caller needs to hear about.
        }
    }
}
