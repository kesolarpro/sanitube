<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SaniTube\Artists\Models\Artist;

/**
 * @mixin Artist
 */
final class ArtistResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'sort_name' => $this->sort_name,
            'slug' => $this->slug,
            'type' => $this->type->value,
            'country' => $this->country,
            'primary_locale' => $this->primary_locale,
            'biography' => $this->biography,
            'is_owner_controlled' => $this->is_owner_controlled,
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toAtomString(),
            'updated_at' => $this->updated_at?->toAtomString(),
        ];
    }
}
