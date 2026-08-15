<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SaniTube\Catalog\Models\Track;
use SaniTube\Releases\Models\Release;
use SaniTube\Releases\Models\ReleaseTrack;

/**
 * @mixin Release
 */
final class ReleaseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'type' => $this->type->value,
            'title' => $this->title,
            'version_title' => $this->version_title,
            'label_name' => $this->label_name,
            'catalogue_number' => $this->catalogue_number,
            'release_date' => $this->release_date?->toDateString(),
            'original_release_date' => $this->original_release_date?->toDateString(),
            'language_code' => $this->language_code,
            'p_line' => $this->p_line,
            'c_line' => $this->c_line,
            'status' => $this->status->value,

            'artists' => ArtistCreditResource::collection($this->whenLoaded('artists')),
            'cover' => new AssetResource($this->whenLoaded('coverAsset')),
            'identifiers' => ExternalIdentifierResource::collection($this->whenLoaded('externalIdentifiers')),

            'tracks' => $this->whenLoaded('tracks', fn () => $this->tracks
                ->map(function (Track $track): array {
                    // The running order lives on the pivot; reading it through
                    // getAttribute keeps the relation's pivot type out of the
                    // Track model, where it does not belong.
                    /** @var ReleaseTrack|null $entry */
                    $entry = $track->getAttribute('pivot');

                    return [
                        'uuid' => $track->uuid,
                        'title' => $track->title,
                        'disc_number' => (int) $entry?->disc_number,
                        'track_number' => (int) $entry?->track_number,
                        'is_focus_track' => (bool) $entry?->is_focus_track,
                    ];
                })->values()),

            'created_at' => $this->created_at?->toAtomString(),
            'updated_at' => $this->updated_at?->toAtomString(),
        ];
    }
}
