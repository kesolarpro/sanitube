<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SaniTube\Catalog\Models\ExternalIdentifier;

/**
 * @mixin ExternalIdentifier
 */
final class ExternalIdentifierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->type->value,
            'namespace' => $this->namespace,
            'value' => $this->value,
            'is_authoritative' => $this->is_authoritative,
        ];
    }
}
