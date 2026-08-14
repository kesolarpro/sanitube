<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SaniTube\Catalog\Models\Composition;
use SaniTube\Catalog\Models\CompositionContributor;

/**
 * @mixin Composition
 */
final class CompositionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'alternative_title' => $this->alternative_title,
            'language_code' => $this->language_code,
            'created_year' => $this->created_year,
            'is_public_domain' => $this->is_public_domain,
            'status' => $this->status->value,
            'contributors' => $this->whenLoaded('contributorCredits', fn () => $this->contributorCredits
                ->map(fn (CompositionContributor $credit): array => [
                    'contributor_uuid' => $credit->contributor?->uuid,
                    'role' => $credit->role->value,
                    'share' => $credit->share,
                    'position' => $credit->position,
                ])->values()),
            'identifiers' => ExternalIdentifierResource::collection(
                $this->whenLoaded('externalIdentifiers'),
            ),
            'created_at' => $this->created_at?->toAtomString(),
            'updated_at' => $this->updated_at?->toAtomString(),
        ];
    }
}
