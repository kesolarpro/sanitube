<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SaniTube\MusicGeneration\Models\MusicGenerationResult;

/**
 * One take, as published.
 *
 * `source_reference` never leaves the platform. It is an expiring provider URL
 * that streams the audio, and publishing it would let a caller fetch a master
 * without going anywhere near SaniTube's own signed-URL policy. `raw` is
 * absent for the same reason it is on an audio analysis: it is the provider's
 * own payload, kept for provenance rather than publication.
 *
 * @mixin MusicGenerationResult
 */
final class MusicGenerationResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'duration_ms' => $this->duration_ms,
            'selected' => $this->selected,
            'imported' => $this->candidate_id !== null,
            'candidate' => $this->whenLoaded('candidate', fn (): ?string => $this->candidate?->uuid),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
