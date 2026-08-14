<?php

declare(strict_types=1);

namespace SaniTube\Observability\Capabilities;

/**
 * One thing the running server can — or cannot — do.
 *
 * A missing capability never takes the application down. It is reported, the
 * features that depend on it are disabled, and the operator is told exactly
 * how to fix it.
 */
final readonly class Capability
{
    /**
     * @param  bool  $required  false when the platform is fully functional without it
     */
    public function __construct(
        public string $key,
        public string $label,
        public CapabilityStatus $status,
        public ?string $detail = null,
        public ?string $remediation = null,
        public bool $required = true,
    ) {}

    public static function available(string $key, string $label, ?string $detail = null): self
    {
        return new self($key, $label, CapabilityStatus::Available, $detail);
    }

    public static function unavailable(
        string $key,
        string $label,
        ?string $detail = null,
        ?string $remediation = null,
    ): self {
        return new self($key, $label, CapabilityStatus::Unavailable, $detail, $remediation);
    }

    public static function degraded(
        string $key,
        string $label,
        ?string $detail = null,
        ?string $remediation = null,
    ): self {
        return new self($key, $label, CapabilityStatus::Degraded, $detail, $remediation);
    }

    public static function optional(
        string $key,
        string $label,
        ?string $detail = null,
        ?string $remediation = null,
    ): self {
        return new self($key, $label, CapabilityStatus::Optional, $detail, $remediation, required: false);
    }

    /**
     * Blocking only when the capability is both required and unavailable.
     */
    public function isBlocking(): bool
    {
        return $this->required && $this->status->isBlocking();
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     status: string,
     *     detail: string|null,
     *     remediation: string|null,
     *     required: bool,
     *     blocking: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'status' => $this->status->value,
            'detail' => $this->detail,
            'remediation' => $this->remediation,
            'required' => $this->required,
            'blocking' => $this->isBlocking(),
        ];
    }
}
