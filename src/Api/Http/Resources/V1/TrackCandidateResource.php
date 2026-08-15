<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SaniTube\Ingestion\Models\TrackCandidate;
use SaniTube\Media\Models\AudioAnalysis;

/**
 * The public face of a proposal.
 *
 * `suggested_title` keeps its name across the boundary on purpose. A field
 * called `title` in an API response is read as the title; a client that
 * displays it beside real catalogue titles will make it look canonical, and
 * the whole point of a candidate is that nothing on it is.
 *
 * The asset is described, never located — the same rule the catalogue
 * endpoints follow.
 *
 * @mixin TrackCandidate
 */
final class TrackCandidateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'source' => $this->source->value,
            'status' => $this->status->value,
            'original_filename' => $this->original_filename,
            'suggested_title' => $this->suggested_title,
            'asset' => new AssetResource($this->whenLoaded('asset')),
            'duplicate_of' => $this->whenLoaded(
                'matchedAsset',
                fn (): ?string => $this->matchedAsset?->uuid,
            ),
            'promoted_track' => $this->whenLoaded(
                'promotedTrack',
                fn (): ?string => $this->promotedTrack?->uuid,
            ),
            'failure_code' => $this->failure_code?->value,

            /*
             * What the review is *for*. A reviewer deciding whether a file
             * belongs in the catalogue needs the measurements, and a review
             * surface that shows only a filename is a surface on which every
             * decision is made blind.
             *
             * Deferred here from MED-001 on purpose: the measurement and the
             * screen that reads it are separate concerns, and building the
             * read model in the ticket that produced the data would have meant
             * building it before knowing what the review needed.
             */
            'analysis' => $this->whenLoaded('asset', fn (): ?array => $this->analysisFor()),

            /*
             * The decision, once one has been taken. `review_note` carries a
             * rejection's reason or an override's justification, and is the
             * only record of why a person overruled the platform.
             */
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'review_note' => $this->review_note,

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * The most recent successful analysis of this candidate's master.
     *
     * Read here rather than eager-loaded through a relation on the candidate:
     * an analysis belongs to an *asset*, and giving `TrackCandidate` a relation
     * to it would put Media's table in Ingestion's model.
     *
     * @return array<string, mixed>|null
     */
    private function analysisFor(): ?array
    {
        $analysis = AudioAnalysis::query()
            ->where('asset_id', $this->asset_id)
            ->orderByDesc('id')
            ->first();

        if (! $analysis instanceof AudioAnalysis) {
            // No analyser on this host, or analysis has not run yet. Null
            // rather than an object of nulls: "not measured" and "measured as
            // nothing" are different facts.
            return null;
        }

        return [
            'succeeded' => $analysis->succeeded,
            'duration_ms' => $analysis->duration_ms,
            'codec' => $analysis->codec,
            'container' => $analysis->container,
            'sample_rate' => $analysis->sample_rate,
            'bit_depth' => $analysis->bit_depth,
            'channels' => $analysis->channels,
            'bitrate' => $analysis->bitrate,
            'loudness_lufs' => $analysis->loudness_lufs,
            'peak_dbfs' => $analysis->peak_dbfs,
            // `raw` is deliberately absent. It is the analyser's own output,
            // kept for diagnosis, and it names codecs, paths and tooling that
            // the platform does not publish.
            'failure_message' => $analysis->failure_message,
            'analyzed_at' => $analysis->analyzed_at?->toIso8601String(),
        ];
    }
}
