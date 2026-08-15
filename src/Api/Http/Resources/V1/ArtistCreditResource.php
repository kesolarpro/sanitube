<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SaniTube\Artists\Models\Artist;

/**
 * An artist as credited on a track or a release.
 *
 * The role and position come from the pivot, which is the only place they
 * exist — there is no denormalised display string to fall out of step with
 * this.
 *
 * @mixin Artist
 */
final class ArtistCreditResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $pivot = $this->resource->pivot;

        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'role' => $pivot?->getAttribute('role') instanceof \BackedEnum
                ? $pivot->getAttribute('role')->value
                : $pivot?->getAttribute('role'),
            'position' => (int) ($pivot?->getAttribute('position') ?? 0),
        ];
    }
}
