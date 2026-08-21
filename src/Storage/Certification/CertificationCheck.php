<?php

declare(strict_types=1);

namespace SaniTube\Storage\Certification;

use SaniTube\Storage\CredentialRedactor;

/**
 * One thing that was tried, and what happened.
 *
 * The detail is redacted **here**, in the constructors every check goes
 * through, rather than at each call site. A certification report is the
 * likeliest place a misconfigured secret surfaces — it quotes whatever the SDK
 * said about a signed request — and it is printed to terminals, written to cron
 * mail and pasted into support threads.
 *
 * **A signed URL never appears in a detail.** The signature *is* the
 * permission: it is scoped to one key and expires in minutes, but a report is
 * read later and by more people than the operator who ran it.
 */
final readonly class CertificationCheck
{
    private function __construct(
        public string $name,
        public CertificationOutcome $outcome,
        public ?string $detail = null,
    ) {}

    public static function passed(string $name): self
    {
        return new self($name, CertificationOutcome::Passed);
    }

    public static function failed(string $name, string $detail): self
    {
        return new self(
            $name,
            CertificationOutcome::Failed,
            CredentialRedactor::fromDiskConfiguration()->redact($detail),
        );
    }

    /**
     * @param  string  $reason  why this provider was never asked — a fact about the deployment
     */
    public static function skipped(string $name, string $reason): self
    {
        return new self($name, CertificationOutcome::Skipped, $reason);
    }

    /**
     * @return array{name: string, outcome: string, detail: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'outcome' => $this->outcome->value,
            'detail' => $this->detail,
        ];
    }
}
