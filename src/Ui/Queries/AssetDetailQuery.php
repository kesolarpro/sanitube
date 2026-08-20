<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use App\Models\User;
use SaniTube\Artwork\Services\MeasuredDimensions;
use SaniTube\Assets\Models\Asset;
use SaniTube\Enrichment\Enums\SuggestionStatus;
use SaniTube\Enrichment\Models\MetadataSuggestion;
use SaniTube\Enrichment\Services\EnrichmentEligibility;
use SaniTube\Media\Models\AudioAnalysis;
use SaniTube\Transcription\Models\Transcript;
use SaniTube\Transcription\Services\TranscriptionEligibility;
use SaniTube\Ui\Assets\AssetPreviewPolicy;

/**
 * One asset, described without saying where it lives — and **without a URL**.
 *
 * The absence of a signed URL here is the point of the whole screen. A signed
 * URL is a temporary bearer credential; minting one every time somebody opens a
 * detail page would create credentials nobody asked for, for every asset merely
 * looked at, and widen both their number and their useful window.
 *
 * What the payload carries instead is whether a preview *would* be allowed, and
 * why not when it would not. The interface renders a button or an explanation
 * from that, and the URL is minted only when the button is pressed.
 */
final readonly class AssetDetailQuery
{
    public function __construct(
        private AssetPreviewPolicy $policy,
        private MeasuredDimensions $dimensions,
        private TranscriptionEligibility $transcription,
        private EnrichmentEligibility $enrichment,
    ) {}

    /**
     * The optional work this asset could still be put through, and why not.
     *
     * **A refusal code rather than a disabled button with no explanation.**
     * Transcription and enrichment both cost money and both refuse for several
     * different reasons — no supplier configured, nothing to reason from, an
     * answer already held, a file nobody verified. A greyed-out button tells a
     * person none of that, and the difference between "this installation has no
     * AI account" and "this file is not audio" is the difference between
     * changing a setting and choosing another file.
     *
     * `held` and `pending` are facts about the database; `refusal` is the
     * eligibility service's own answer, asked rather than re-derived here. Two
     * copies of "may this be transcribed" would drift, and the one that costs
     * money is the one that must not.
     *
     * @return array<string, mixed>
     */
    private function work(Asset $asset): array
    {
        $transcriptionRefusal = $this->transcription->refusalFor($asset);
        $enrichmentRefusal = $this->enrichment->refusalFor($asset);

        return [
            'transcription' => [
                'held' => Transcript::query()->where('asset_id', $asset->getKey())->exists(),
                'refusal' => $transcriptionRefusal?->value,
            ],
            'enrichment' => [
                'waiting' => MetadataSuggestion::query()
                    ->where('asset_id', $asset->getKey())
                    ->where('status', SuggestionStatus::Proposed)
                    ->exists(),
                'refusal' => $enrichmentRefusal?->value,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forAsset(Asset $asset, User $viewer): array
    {
        $asset->load(['parent', 'duplicateOf']);
        $asset->loadCount('derivatives');

        $analysis = AudioAnalysis::query()
            ->where('asset_id', $asset->getKey())
            ->orderByDesc('analyzed_at')
            ->first();

        $decision = $this->policy->decide($asset, $viewer);

        return [
            'uuid' => $asset->uuid,
            'kind' => $asset->kind->value,
            'status' => $asset->status->value,
            'mime_type' => $asset->mime_type,
            'byte_size' => $asset->byte_size,
            'checksum_short' => substr((string) $asset->sha256, 0, 12),
            'duration_ms' => $asset->duration_ms,
            'sample_rate' => $asset->sample_rate,
            'bit_depth' => $asset->bit_depth,
            'channels' => $asset->channels,
            // Measured, never the never-written `assets.width`. See
            // MeasuredDimensions.
            'width' => $this->dimensions->for($asset)['width'],
            'height' => $this->dimensions->for($asset)['height'],
            'stored_at' => $asset->stored_at?->toAtomString(),
            'verified_at' => $asset->verified_at?->toAtomString(),
            'created_at' => $asset->created_at?->toAtomString(),
            'updated_at' => $asset->updated_at?->toAtomString(),

            // Relationships by UUID only.
            'parent' => $asset->parent === null ? null : [
                'uuid' => $asset->parent->uuid,
                'kind' => $asset->parent->kind->value,
            ],
            'duplicate_of' => $asset->duplicateOf === null ? null : [
                'uuid' => $asset->duplicateOf->uuid,
                'kind' => $asset->duplicateOf->kind->value,
            ],
            'derivative_count' => (int) $asset->derivatives_count,

            'analysis' => $analysis === null ? null : [
                'succeeded' => (bool) $analysis->succeeded,
                'analyzer' => $analysis->analyzer,
                'codec' => $analysis->codec,
                'container' => $analysis->container,
                'sample_rate' => $analysis->sample_rate,
                'bit_depth' => $analysis->bit_depth,
                'channels' => $analysis->channels,
                'bitrate' => $analysis->bitrate,
                'loudness_lufs' => $analysis->loudness_lufs === null ? null : (float) $analysis->loudness_lufs,
                'analyzed_at' => $analysis->analyzed_at?->toAtomString(),
                // `failure_message` is withheld: it quotes the analyser, and on
                // a failed read that quote contains a filesystem path.
                'failed' => ! $analysis->succeeded,
            ],

            // Whether a preview *could* be minted — not a preview.
            'preview' => [
                'available' => $decision->isAllowed(),
                'reason' => $decision->isAllowed() ? null : $decision->value,
            ],

            'work' => $this->work($asset),
        ];
    }
}
