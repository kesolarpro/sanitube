<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Services;

use Illuminate\Support\Facades\DB;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Services\AssetStorageService;
use SaniTube\Assets\Services\AssetVerificationService;
use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Enums\TrackCandidateStatus;
use SaniTube\Ingestion\Events\TrackCandidateCreated;
use SaniTube\Ingestion\Models\TrackCandidate;
use SaniTube\MusicGeneration\Contracts\GeneratedAudioReader;
use SaniTube\MusicGeneration\Enums\MusicGenerationStatus;
use SaniTube\MusicGeneration\Exceptions\GenerationException;
use SaniTube\MusicGeneration\Models\MusicGenerationResult;

/**
 * Turns a chosen generation result into a candidate.
 *
 * The route is deliberately long:
 *
 * ```
 * result → fetch audio → Asset → VERIFIED → TrackCandidate (GENERATED)
 * ```
 *
 * and it stops there. **A generation never becomes a Track.** Generated audio
 * is exactly the material that most needs a person to listen before it enters
 * the catalogue — it was produced by a machine from a sentence — so it joins
 * the same review queue as an imported file, and CAT-001 promotes it or does
 * not. A shortcut from generation to Track would let a campaign write nine
 * hundred rows into the source of truth overnight.
 *
 * **Selecting does not delete.** The other takes keep their rows, their audio
 * and their provider identifiers; `selected` is a flag. A reviewer who changes
 * their mind an hour later must still be able to hear take two, and a platform
 * that destroyed the alternatives to save space would have destroyed the only
 * copies.
 *
 * **Selecting twice is free.** The result carries `asset_id` and
 * `candidate_id` once imported, and a second call returns them rather than
 * fetching the audio again — the same asset-id-before-upload ordering ING-001
 * uses, for the same reason: a crash mid-transfer must leave a retry with one
 * address to overwrite, not a second orphaned object.
 */
final readonly class SelectMusicGenerationResult
{
    public function __construct(
        private AssetStorageService $assets,
        private AssetVerificationService $verification,
        private GeneratedAudioReader $audio,
    ) {}

    public function handle(MusicGenerationResult $result): TrackCandidate
    {
        $generation = $result->generation;

        if ($generation->status !== MusicGenerationStatus::Completed) {
            throw GenerationException::generationNotComplete($generation->status->value);
        }

        if ($result->candidate_id !== null) {
            $existing = TrackCandidate::query()->find($result->candidate_id);

            if ($existing instanceof TrackCandidate) {
                // Already imported. Re-selecting is a no-op, not a second
                // asset and a second candidate.
                $this->markSelected($result);

                return $existing;
            }
        }

        if (! $result->hasAudio()) {
            throw GenerationException::resultHasNoAudio($result->uuid);
        }

        $asset = $this->assetFor($result);

        $this->fill($result, $asset);

        $verified = $this->verification->verify($asset->refresh());

        if ($verified->status !== AssetStatus::Verified) {
            // The bytes did not come back as they went in. A candidate
            // referencing an unverified master is a candidate nobody can
            // promote, so this fails loudly rather than creating one.
            throw GenerationException::resultHasNoAudio($result->uuid);
        }

        return DB::transaction(function () use ($result, $verified): TrackCandidate {
            $candidate = TrackCandidate::query()->create([
                'source' => IngestionSource::Generated,
                'asset_id' => $verified->id,
                'original_filename' => $this->filename($result),
                // A guess, and named as one — exactly as an imported file's is.
                // The provider's title came from the prompt, not from anyone
                // deciding what this recording is called.
                'suggested_title' => $result->title,
                'matched_asset_id' => $verified->duplicate_of_asset_id,
                // PENDING: MED-001 analyses it and settles it, the same as any
                // other candidate. Generated audio gets no shortcut.
                'status' => $verified->duplicate_of_asset_id === null
                    ? TrackCandidateStatus::Pending
                    : TrackCandidateStatus::Duplicate,
            ]);

            $result->forceFill([
                'selected' => true,
                'candidate_id' => $candidate->id,
            ])->save();

            event(new TrackCandidateCreated($candidate));

            return $candidate;
        });
    }

    /**
     * The asset these bytes go into, created once and reused by every retry.
     */
    private function assetFor(MusicGenerationResult $result): Asset
    {
        if ($result->asset_id !== null) {
            $existing = Asset::query()->find($result->asset_id);

            if ($existing instanceof Asset) {
                return $existing;
            }
        }

        $asset = $this->assets->register(
            kind: AssetKind::AudioMaster,
            originalFilename: $this->filename($result),
        );

        // Written before any bytes move, so everything after this point is
        // resumable: the asset's object key is already decided.
        $result->forceFill(['asset_id' => $asset->id])->save();

        return $asset;
    }

    private function fill(MusicGenerationResult $result, Asset $asset): void
    {
        if ($asset->status->isImmutable()) {
            // A previous attempt already stored them; re-fetching a large
            // file from the provider would achieve nothing.
            return;
        }

        $stream = $this->audio->open((string) $result->source_reference);

        try {
            $this->assets->store($asset, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function markSelected(MusicGenerationResult $result): void
    {
        if (! $result->selected) {
            $result->forceFill(['selected' => true])->save();
        }
    }

    private function filename(MusicGenerationResult $result): string
    {
        $reference = (string) $result->source_reference;
        $name = basename(parse_url($reference, PHP_URL_PATH) ?: $reference);

        return $name === '' ? $result->provider_result_id.'.mp3' : $name;
    }
}
