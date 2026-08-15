<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SaniTube\Ingestion\Models\TrackCandidate;

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
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
