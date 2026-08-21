<?php

declare(strict_types=1);

namespace SaniTube\Media\Services;

use Illuminate\Support\Carbon;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Services\AssetStorageService;
use SaniTube\Media\Contracts\AudioFingerprinter;
use SaniTube\Media\Contracts\FingerprintsStoredObject;
use SaniTube\Media\Exceptions\FingerprintFailed;
use SaniTube\Media\Fingerprinters\StrategyAudioFingerprinter;
use SaniTube\Media\Models\AudioFingerprint;
use Throwable;

/**
 * Taking one asset's acoustic fingerprint, once.
 *
 * **A missing fingerprint is never a failure of the asset.** A file this
 * platform cannot fingerprint is still stored, still analysed, still
 * promotable — it simply cannot be compared acoustically. On a shared cPanel
 * account, which will not have Chromaprint installed, that is the ordinary
 * case rather than the exceptional one, and treating it as an error would make
 * the whole library look broken.
 *
 * **Idempotent by algorithm version.** Re-running over an asset that already
 * has a fingerprint from this version returns the stored one rather than
 * decoding the file again. A new version adds a row instead of replacing one,
 * so a recalibration can compare old against new rather than losing what it
 * was recalibrating from.
 *
 * The file has to be local to be decoded, so the object is streamed to a
 * temporary file and removed afterwards — including when the tool fails, which
 * is the case that leaves litter if nobody writes the `finally`.
 */
final readonly class FingerprintAsset
{
    public function __construct(
        private AudioFingerprinter $fingerprinter,
        private AssetStorageService $storage,
    ) {}

    public function isAvailable(): bool
    {
        return $this->fingerprinter->isAvailable();
    }

    /**
     * What a fingerprint taken right now would be recorded under.
     *
     * Published because the backlog has to ask the same question this class
     * answers — *has this asset been fingerprinted by the tool this
     * installation has today* — and two spellings of it would drift into a
     * sweep that queues work already done, or worse, skips work never done
     * because the version moved.
     */
    public function algorithm(): string
    {
        return $this->fingerprinter->name().':'.$this->fingerprinter->version();
    }

    /**
     * Whether this asset has never been fingerprinted by the current tool.
     *
     * A new algorithm version makes every asset outstanding again, which is
     * the intended behaviour: a recalibration adds a row rather than replacing
     * one, so old and new can be compared instead of one being lost.
     */
    public function needs(Asset $asset): bool
    {
        return ! AudioFingerprint::query()
            ->where('asset_id', $asset->id)
            ->where('algorithm', $this->algorithm())
            ->exists();
    }

    /**
     * @return AudioFingerprint|null null when this installation cannot fingerprint
     */
    public function handle(Asset $asset, bool $force = false): ?AudioFingerprint
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $algorithm = $this->algorithm();

        $existing = AudioFingerprint::query()
            ->where('asset_id', $asset->id)
            ->where('algorithm', $algorithm)
            ->first();

        if ($existing instanceof AudioFingerprint && ! $force) {
            return $existing;
        }

        try {
            // WRK-002: when the chosen path reads shared storage itself -- a
            // worker does -- the object never touches this machine's disk.
            if ($this->fingerprinter instanceof FingerprintsStoredObject && $this->readsStoredObjects()) {
                $result = $this->fingerprinter->fingerprintStored($asset->path);
            } else {
                $path = $this->spool($asset);

                if ($path === null) {
                    return null;
                }

                try {
                    $result = $this->fingerprinter->fingerprint($path);
                } finally {
                    @unlink($path);
                }
            }
        } catch (FingerprintFailed) {
            // Recorded as absent rather than as a broken asset. The operations
            // screen reports how much of the library is comparable; nothing
            // downstream treats a missing fingerprint as a missing file.
            return null;
        }

        return AudioFingerprint::query()->updateOrCreate(
            ['asset_id' => $asset->id, 'algorithm' => $algorithm],
            [
                'tool' => $this->fingerprinter->name(),
                'tool_version' => $this->fingerprinter->version(),
                'fingerprint' => $result->fingerprint,
                'fingerprint_hash' => $result->hash(),
                'duration_seconds' => $result->durationSeconds,
                'computed_at' => Carbon::now(),
            ],
        );
    }

    /**
     * Bring the object down to somewhere the tool can read it.
     *
     * Streamed rather than read whole: a master does not fit in a shared host's
     * memory limit, and the fingerprinter only reads the opening of the file
     * anyway.
     */
    /**
     * Whether the chosen execution path reads storage rather than a file.
     *
     * Asked of the strategy rather than assumed from the interface: the
     * dispatcher implements {@see FingerprintsStoredObject} whichever road it
     * takes, because it cannot know at construction which that will be.
     */
    private function readsStoredObjects(): bool
    {
        return ! $this->fingerprinter instanceof StrategyAudioFingerprinter
            || $this->fingerprinter->prefersStoredObject();
    }

    private function spool(Asset $asset): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'sanitube-fp-');

        if ($path === false) {
            return null;
        }

        try {
            $source = $this->storage->readStream($asset);
            $destination = fopen($path, 'wb');

            if ($destination === false) {
                @unlink($path);

                return null;
            }

            try {
                stream_copy_to_stream($source, $destination);
            } finally {
                if (is_resource($source)) {
                    fclose($source);
                }

                fclose($destination);
            }
        } catch (Throwable) {
            @unlink($path);

            return null;
        }

        return $path;
    }
}
