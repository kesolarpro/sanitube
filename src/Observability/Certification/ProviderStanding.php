<?php

declare(strict_types=1);

namespace SaniTube\Observability\Certification;

/**
 * One provider area, one status, and the sentence that earns it.
 */
final readonly class ProviderStanding
{
    public function __construct(
        public string $key,
        public string $label,
        public CertificationStatus $status,
        public string $detail,
        public ?string $certifiedAt = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'status' => $this->status->value,
            'detail' => $this->detail,
            'certified_at' => $this->certifiedAt,
        ];
    }
}
