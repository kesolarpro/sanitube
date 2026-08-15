<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SaniTube\MusicGeneration\Models\GenerationProject;

/**
 * @mixin GenerationProject
 */
final class GenerationProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'status' => $this->status->value,
            'target_track_count' => $this->target_track_count,
            'default_language' => $this->default_language,
            'default_genre' => $this->default_genre,
            'default_style_prompt' => $this->default_style_prompt,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
