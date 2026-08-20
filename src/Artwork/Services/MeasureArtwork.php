<?php

declare(strict_types=1);

namespace SaniTube\Artwork\Services;

use Illuminate\Support\Carbon;
use SaniTube\Artwork\Contracts\ImageMeasurer;
use SaniTube\Artwork\Models\ArtworkAnalysis;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Services\AssetStorageService;
use SaniTube\Media\Services\AnalyzeAsset;
use Throwable;

/**
 * Measures one artwork asset, once per measurer version.
 *
 * The same shape {@see AnalyzeAsset} uses for audio,
 * and for the same reasons: the measurer needs a *file* while the platform
 * holds *objects*, so the object is streamed to a temporary file and the
 * temporary file is removed afterwards — including when the measurement fails,
 * which is the case that fills a shared host's disk if nobody writes the
 * `finally`.
 *
 * **A file that cannot be read is recorded as a failed measurement, not
 * skipped.** The distinction matters at the other end: a release validator has
 * to tell "nobody has looked at this cover" from "somebody looked and it is not
 * an image", because the first is a warning to go and look and the second is an
 * error about the file. Skipping would collapse both into the first.
 *
 * **An installation that cannot measure records nothing at all**, and that is
 * not the same as a failure either. A row saying "failed" would be
 * indistinguishable from a corrupt file and would still be there long after the
 * capability arrived.
 */
final readonly class MeasureArtwork
{
    public function __construct(
        private ImageMeasurer $measurer,
        private AssetStorageService $storage,
    ) {}

    public function handle(Asset $asset, bool $force = false): ?ArtworkAnalysis
    {
        if (! $this->measurer->isAvailable()) {
            return null;
        }

        if ($asset->kind !== AssetKind::Artwork) {
            // Measuring a master as though it were a cover would write a row
            // asserting an audio file is not a valid image, which is true and
            // useless.
            return null;
        }

        // Measuring bytes nobody has verified would describe an object that
        // may not be the one the catalogue means.
        if ($asset->status !== AssetStatus::Verified) {
            return null;
        }

        $existing = ArtworkAnalysis::query()
            ->where('asset_id', $asset->id)
            ->where('measurer', $this->measurer->name())
            ->where('measurer_version', $this->measurer->version())
            ->first();

        if ($existing instanceof ArtworkAnalysis && ! $force) {
            return $existing;
        }

        $path = $this->spool($asset);

        if ($path === null) {
            // The object could not be fetched. That says nothing about the
            // image, so nothing is recorded about the image.
            return null;
        }

        try {
            $measurement = $this->measurer->measure($path);
        } catch (Throwable) {
            $measurement = null;
        } finally {
            @unlink($path);
        }

        return ArtworkAnalysis::query()->updateOrCreate(
            [
                'asset_id' => $asset->id,
                'measurer' => $this->measurer->name(),
                'measurer_version' => $this->measurer->version(),
            ],
            [
                'succeeded' => $measurement !== null,
                'width_px' => $measurement?->widthPx,
                'height_px' => $measurement?->heightPx,
                'mime_type' => $measurement?->mimeType,
                'bytes' => $measurement?->bytes,
                'channels' => $measurement?->channels,
                'bits' => $measurement?->bits,
                'measured_at' => Carbon::now(),
            ],
        );
    }

    /**
     * Bring the object down to somewhere a measurer can read it.
     *
     * Streamed rather than read whole: artwork is smaller than a master, but
     * "smaller than a master" is not a memory limit.
     */
    private function spool(Asset $asset): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'sanitube-art-');

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
