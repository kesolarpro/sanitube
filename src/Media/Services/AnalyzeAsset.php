<?php

declare(strict_types=1);

namespace SaniTube\Media\Services;

use Illuminate\Support\Carbon;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Services\AssetStorageService;
use SaniTube\Media\Contracts\AudioAnalyzer;
use SaniTube\Media\Models\AudioAnalysis;
use Throwable;

/**
 * Analyses one asset, once per analyser version.
 *
 * The awkward part is that the analyser needs a *file* and the platform holds
 * *objects*. On a local disk those are the same thing; on object storage they
 * are not, and an analyser that assumed a path would work in development and
 * fail in production. So the object is streamed to a temporary file, probed,
 * and the temporary file is removed — every provider, same code path.
 *
 * That copy is the cost of provider independence and it is bounded: it happens
 * once per asset per analyser version, in a queued job, never in a request.
 */
final readonly class AnalyzeAsset
{
    public function __construct(
        private AudioAnalyzer $analyzer,
        private AssetStorageService $storage,
    ) {}

    /**
     * @return AudioAnalysis|null null when this server cannot analyse anything
     */
    public function handle(Asset $asset, bool $force = false): ?AudioAnalysis
    {
        if (! $this->analyzer->isAvailable()) {
            // Not a failure of the asset, and deliberately not recorded as
            // one. A row saying "failed" would be indistinguishable from a
            // corrupt file, and would still be there long after FFmpeg was
            // installed.
            return null;
        }

        $existing = $this->existing($asset);

        if ($existing instanceof AudioAnalysis && ! $force) {
            return $existing;
        }

        // Analysing bytes that have not been verified would describe an object
        // nobody has confirmed is the one the catalogue means.
        if ($asset->status !== AssetStatus::Verified) {
            return null;
        }

        $temporary = $this->copyToDisk($asset);

        try {
            $result = $this->analyzer->analyze($temporary);
        } finally {
            @unlink($temporary);
        }

        return AudioAnalysis::query()->updateOrCreate(
            [
                'asset_id' => $asset->id,
                'analyzer' => $this->analyzer->name(),
                'analyzer_version' => $this->analyzer->version(),
            ],
            [
                'succeeded' => $result->succeeded,
                'duration_ms' => $result->durationMs,
                'codec' => $result->codec,
                'container' => $result->container,
                'sample_rate' => $result->sampleRate,
                'bit_depth' => $result->bitDepth,
                'channels' => $result->channels,
                'bitrate' => $result->bitrate,
                'loudness_lufs' => $result->loudnessLufs,
                'peak_dbfs' => $result->peakDbfs,
                'raw' => $result->raw === [] ? null : $result->raw,
                'failure_message' => $result->failureMessage,
                'analyzed_at' => Carbon::now(),
            ],
        );
    }

    public function isAvailable(): bool
    {
        return $this->analyzer->isAvailable();
    }

    /**
     * Whether this kind of asset is worth probing at all.
     */
    public static function appliesTo(Asset $asset): bool
    {
        return in_array($asset->kind, [
            AssetKind::AudioMaster,
            AssetKind::AudioDerivative,
            AssetKind::Preview,
            AssetKind::Stem,
        ], true);
    }

    private function existing(Asset $asset): ?AudioAnalysis
    {
        return AudioAnalysis::query()
            ->where('asset_id', $asset->id)
            ->where('analyzer', $this->analyzer->name())
            ->where('analyzer_version', $this->analyzer->version())
            ->first();
    }

    /**
     * Stream the object to a local file the probe can open.
     */
    private function copyToDisk(Asset $asset): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'sanitube-analysis-');

        if ($temporary === false) {
            throw new \RuntimeException('Could not create a temporary file for analysis.');
        }

        $source = $this->storage->readStream($asset);
        $target = fopen($temporary, 'wb');

        if ($target === false) {
            @unlink($temporary);

            throw new \RuntimeException(sprintf('Could not open [%s] for writing.', $temporary));
        }

        try {
            stream_copy_to_stream($source, $target);
        } catch (Throwable $exception) {
            @unlink($temporary);

            throw $exception;
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }

            if (is_resource($target)) {
                fclose($target);
            }
        }

        return $temporary;
    }
}
