<?php

declare(strict_types=1);

namespace SaniTube\Transcription\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Services\AssetStorageService;
use SaniTube\Media\Services\FingerprintAsset;
use SaniTube\Transcription\Exceptions\TranscriptionFailed;
use SaniTube\Transcription\Models\Transcript;
use SaniTube\Transcription\Models\TranscriptSegment;
use SaniTube\Transcription\TranscriptionManager;
use Throwable;

/**
 * Transcribing one asset, once.
 *
 * **A missing transcript is never a failure of the asset.** Most installations
 * will configure no supplier at all, and one that does will still meet files a
 * model cannot make sense of. Such an asset is stored, analysed, deduplicated
 * and distributable exactly as before — it simply has no transcript, and
 * treating that as an error would make an ordinary library look broken. The
 * same reasoning {@see FingerprintAsset} follows for
 * Chromaprint, and for the same practical reason.
 *
 * **Idempotent by provider version.** Re-running against an asset already
 * transcribed by this version of this supplier returns the stored row rather
 * than paying for the call again. A version bump makes a genuinely different
 * transcript a different row, so a later comparison has something to compare.
 *
 * **The provider is handed a local path, never a URL.** Everything is streamed
 * down to a temporary file first and removed afterwards, including when the
 * call fails — which is the case that litters a shared account's disk if nobody
 * writes the `finally`. It also means no supplier adapter can be talked into
 * fetching an arbitrary address on the platform's behalf.
 */
final readonly class TranscribeAsset
{
    public function __construct(
        private TranscriptionManager $providers,
        private AssetStorageService $storage,
    ) {}

    /**
     * @param  string|null  $languageHint  what the operator believes it is. A hint, never
     *                                     an assertion: the supplier may report something
     *                                     else and the disagreement is kept.
     * @return Transcript|null null when this installation cannot transcribe, or could not
     */
    public function handle(Asset $asset, ?string $languageHint = null, bool $force = false): ?Transcript
    {
        $provider = $this->providers->default();

        if (! $provider->isAvailable()) {
            return null;
        }

        $existing = Transcript::query()
            ->where('asset_id', $asset->id)
            ->where('provider', $provider->name())
            ->where('provider_version', $provider->version())
            ->first();

        if ($existing instanceof Transcript && ! $force) {
            return $existing;
        }

        $path = $this->spool($asset);

        if ($path === null) {
            return null;
        }

        try {
            $result = $provider->transcribe($path, $languageHint);
        } catch (TranscriptionFailed) {
            // Absent, not broken. The reason code has already been raised where
            // it can be logged; nothing downstream treats a missing transcript
            // as a missing file.
            return null;
        } finally {
            @unlink($path);
        }

        // A supplier that heard nothing said so, and that is a result rather
        // than a row. Storing an empty transcript would put a record in the
        // table asserting this asset contains no speech, which nobody
        // established -- an instrumental and a failed decode look identical
        // from here.
        if ($result->isEmpty()) {
            return null;
        }

        return DB::transaction(function () use ($asset, $provider, $result, $languageHint): Transcript {
            $transcript = Transcript::query()->updateOrCreate(
                [
                    'asset_id' => $asset->id,
                    'provider' => $provider->name(),
                    'provider_version' => $provider->version(),
                ],
                [
                    'model' => $result->model,
                    'detected_language' => $result->languageCode,
                    'language_hint' => $languageHint,
                    'text' => $result->text,
                    'covered_seconds' => $result->durationSeconds,
                    'transcribed_at' => Carbon::now(),
                ],
            );

            // Replaced wholesale rather than merged. Segment boundaries move
            // between runs, so matching old rows to new ones is guesswork, and
            // a half-updated timeline is worse than either version of it.
            $transcript->segments()->delete();

            foreach ($result->segments as $position => $segment) {
                TranscriptSegment::query()->create([
                    'transcript_id' => $transcript->id,
                    'position' => $position,
                    'start_ms' => $segment->startMs,
                    'end_ms' => $segment->endMs,
                    'text' => $segment->text,
                ]);
            }

            return $transcript->refresh();
        });
    }

    /**
     * Bring the object down to somewhere a supplier can read it.
     *
     * Streamed rather than read whole: a master does not fit in a shared host's
     * memory limit.
     */
    private function spool(Asset $asset): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'sanitube-tr-');

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
