<?php

declare(strict_types=1);

namespace SaniTube\Observability\Capabilities;

use SaniTube\Storage\Certification\CertificationCheck;
use SaniTube\Storage\CredentialRedactor;
use SaniTube\Storage\StorageHealth;

/**
 * One thing the running server can — or cannot — do.
 *
 * A missing capability never takes the application down. It is reported, the
 * features that depend on it are disabled, and the operator is told exactly
 * how to fix it.
 *
 * **The detail is scrubbed here, in the one constructor every capability goes
 * through** — the same place {@see StorageHealth} and
 * {@see CertificationCheck} do it, and for
 * the same reason. Two callers hand this an exception's own words:
 * `ObjectStorageDetector` when a provider will not resolve, and
 * `CapabilityRegistry` as a catch-all around *every* detector. Resolving an
 * S3-compatible provider constructs a client from the configured key and
 * secret, so the exception that comes back out is exactly the one that quotes
 * them — and a detail is rendered on the System screen, returned by
 * `/api/v1/health/capabilities`, and printed by the doctor, whose output is
 * what somebody pastes into a support thread.
 *
 * The remediation is left alone. Every one of them is a sentence written here,
 * interpolating provider names and setting names rather than values.
 */
final readonly class Capability
{
    public ?string $detail;

    /**
     * @param  bool  $required  false when the platform is fully functional without it
     */
    public function __construct(
        public string $key,
        public string $label,
        public CapabilityStatus $status,
        ?string $detail = null,
        public ?string $remediation = null,
        public bool $required = true,
    ) {
        $this->detail = CredentialRedactor::scrub($detail);
    }

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
