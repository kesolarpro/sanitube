<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SaniTube\MusicGeneration\Models\MusicGeneration;

/**
 * The public face of a generation.
 *
 * `provider_job_id` is deliberately absent. It is the provider's handle on a
 * billable job, and publishing it hands a caller the ability to correlate
 * SaniTube records with a vendor account. `provider` is published because a
 * label needs to know which supplier made a recording — that is provenance,
 * not a credential.
 *
 * @mixin MusicGeneration
 */
final class MusicGenerationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'provider' => $this->provider,
            'status' => $this->status->value,
            'prompt' => $this->prompt,
            'style_prompt' => $this->style_prompt,
            'instrumental' => $this->instrumental,
            'language_code' => $this->language_code,
            'model' => $this->model,

            // Never inferred from the fact that a generation succeeded.
            'commercial_rights_status' => $this->commercial_rights_status->value,
            'terms_snapshot' => $this->whenLoaded(
                'termsSnapshot',
                fn (): ?string => $this->termsSnapshot?->uuid,
            ),

            'project' => $this->whenLoaded('project', fn (): ?string => $this->project?->uuid),
            'results' => MusicGenerationResultResource::collection($this->whenLoaded('results')),

            'failure_reason' => $this->failure_reason,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
