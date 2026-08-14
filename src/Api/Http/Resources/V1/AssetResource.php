<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SaniTube\Assets\Models\Asset;

/**
 * The public face of an asset.
 *
 * Everything that would let a caller reach the bytes is absent: no disk, no
 * path, no URL, no internal id. A master is served — if it ever is — through
 * an expiring URL minted for a specific request, never through a field in a
 * listing.
 *
 * @mixin Asset
 */
final class AssetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'kind' => $this->kind->value,
            'duration_ms' => $this->duration_ms,
            'byte_size' => $this->byte_size,
            'status' => $this->status->value,
        ];
    }
}
