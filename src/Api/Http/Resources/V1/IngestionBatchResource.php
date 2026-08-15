<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SaniTube\Ingestion\Models\IngestionBatch;

/**
 * The public face of an import operation.
 *
 * Counts are computed rather than stored, so what a caller sees is what the
 * items actually say — a progress bar drawn from a denormalised counter can
 * reach 100% while work is still in flight.
 *
 * No storage vocabulary leaves here: no provider name, no prefix, no object
 * key. Where the material came from is operational detail, and a listing
 * endpoint is not the place to publish the shape of someone's storage.
 *
 * @mixin IngestionBatch
 */
final class IngestionBatchResource extends JsonResource
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
            'total' => $this->total(),
            'items' => $this->itemCounts(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
