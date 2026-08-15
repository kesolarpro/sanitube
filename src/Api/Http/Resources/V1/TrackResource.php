<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SaniTube\Catalog\Models\Track;

/**
 * @mixin Track
 */
final class TrackResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'version_title' => $this->version_title,
            'duration_ms' => $this->duration_ms,
            'language_code' => $this->language_code,
            'is_instrumental' => $this->is_instrumental,
            'is_explicit' => $this->is_explicit,
            'bpm' => $this->bpm,
            'musical_key' => $this->musical_key,
            'genre_primary' => $this->genre_primary,
            'genre_secondary' => $this->genre_secondary,
            'recording_year' => $this->recording_year,
            'p_line' => $this->p_line,
            'status' => $this->status->value,
            'source' => $this->source->value,

            // The composition is referenced by its public identity only; the
            // foreign key that joins them never leaves the database.
            'composition' => $this->whenLoaded('composition', fn () => $this->composition === null ? null : [
                'uuid' => $this->composition->uuid,
                'title' => $this->composition->title,
            ]),

            'artists' => ArtistCreditResource::collection($this->whenLoaded('artists')),
            'master' => new AssetResource($this->whenLoaded('masterAsset')),
            'identifiers' => ExternalIdentifierResource::collection($this->whenLoaded('externalIdentifiers')),

            'created_at' => $this->created_at?->toAtomString(),
            'updated_at' => $this->updated_at?->toAtomString(),
        ];
    }
}
