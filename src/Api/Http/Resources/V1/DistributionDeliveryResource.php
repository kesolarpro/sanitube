<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SaniTube\Distribution\Models\DistributionDelivery;
use SaniTube\Storage\CredentialRedactor;

/**
 * The public face of a delivery.
 *
 * `external_release_id` and `idempotency_key` never cross this boundary. The
 * first identifies a record in somebody else's account; the second is the
 * token that makes a repeat submission recognisable, and a caller holding it
 * could construct one.
 *
 * `provider` is published — a label must know which distributor has its
 * release, and that is provenance rather than a credential.
 *
 * @mixin DistributionDelivery
 */
final class DistributionDeliveryResource extends JsonResource
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
            'release' => $this->whenLoaded('release', fn (): ?string => $this->release?->uuid),
            // Scrubbed on the way out as well as on the way in. The outage
            // path in SubmitDelivery stores an exception's own words, and a
            // client's 403 quotes the signed request it was making.
            'failure_reason' => CredentialRedactor::scrub($this->failure_reason),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'live_at' => $this->live_at?->toIso8601String(),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'attempts' => $this->whenCounted('attempts'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
