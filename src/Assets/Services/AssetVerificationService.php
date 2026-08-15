<?php

declare(strict_types=1);

namespace SaniTube\Assets\Services;

use Illuminate\Support\Carbon;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Exceptions\AssetStorageException;
use SaniTube\Assets\Models\Asset;
use SaniTube\Storage\StorageManager;

/**
 * Confirms that an asset's bytes are still the bytes it claims.
 *
 * VERIFIED is the status the rest of the platform trusts: a track is not ready
 * without a verified master, a release is not ready without verified artwork.
 * So it is only ever written after the object has been read back out of
 * storage and hashed. **No code path may set VERIFIED without that read.**
 *
 * Verification is not a one-off gate at upload time either. Stores acquire
 * lifecycle rules, credentials get rotated to point somewhere else, someone
 * restores a backup over the top. Re-verification is how those are found
 * before a distributor does.
 *
 * The three failure modes are kept distinct because they call for different
 * responses:
 *
 *   - MISSING     — the object is not there. A restore, or a mistake.
 *   - QUARANTINED — the object is there and is not what it should be. Worse:
 *                   something rewrote a master.
 *   - unchanged   — storage could not be reached at all. Not evidence about
 *                   the asset, so the asset's status is left alone.
 */
final readonly class AssetVerificationService
{
    public function __construct(private StorageManager $storage) {}

    public function verify(Asset $asset): Asset
    {
        if (! $asset->status->isImmutable()) {
            throw AssetStorageException::notStored($asset->status);
        }

        $provider = $this->storage->provider($asset->disk);

        // Storage failures are deliberately *not* caught. An unreachable
        // provider says nothing about the asset, and recording MISSING because
        // a call timed out would turn a network blip into a catalogue-wide
        // incident — one that re-verifying afterwards could not undo, because
        // whatever read the status in the meantime has already acted on it.
        // Letting the exception out leaves the asset exactly as it was.
        if (! $provider->exists($asset->path)) {
            return $this->mark($asset, AssetStatus::Missing);
        }

        $size = $provider->size($asset->path);
        $sha256 = $provider->checksum($asset->path);

        if ($size !== $asset->byte_size || ! hash_equals((string) $asset->sha256, $sha256)) {
            return $this->mark($asset, AssetStatus::Quarantined);
        }

        return $this->mark($asset, AssetStatus::Verified, verified: true);
    }

    /**
     * Verify a batch, reporting what each one turned out to be.
     *
     * @param  iterable<Asset>  $assets
     * @return array<string, AssetStatus> asset uuid => resulting status
     */
    public function verifyAll(iterable $assets): array
    {
        $results = [];

        foreach ($assets as $asset) {
            $results[$asset->uuid] = $this->verify($asset)->status;
        }

        return $results;
    }

    private function mark(Asset $asset, AssetStatus $status, bool $verified = false): Asset
    {
        $attributes = ['status' => $status];

        if ($verified) {
            $attributes['verified_at'] = Carbon::now();
        }

        $asset->forceFill($attributes)->save();

        return $asset->refresh();
    }
}
