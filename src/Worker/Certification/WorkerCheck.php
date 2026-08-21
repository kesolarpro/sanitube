<?php

declare(strict_types=1);

namespace SaniTube\Worker\Certification;

use SaniTube\Storage\Certification\CertificationCheck;
use SaniTube\Storage\CredentialRedactor;

/**
 * One thing that was tried against a real worker, and what happened.
 *
 * Modelled on {@see CertificationCheck} and
 * deliberately not reusing it, because the two redact against different
 * secrets. That one masks the values the *disks* are configured with; the
 * secret in reach here is the **worker token**, which is not a disk credential
 * and which §6 forbids printing — not the token, and not a prefix of it.
 *
 * Both then go through `scrub()`, which removes every address. A certification
 * report is read later and by more people than the operator who ran it, and the
 * worker's URL is the one thing in it that says where a private machine lives.
 * The worker is named by the name it announces about itself.
 */
final readonly class WorkerCheck
{
    private function __construct(
        public string $name,
        public WorkerCheckOutcome $outcome,
        public ?string $detail = null,
    ) {}

    public static function passed(string $name, ?string $detail = null): self
    {
        return new self($name, WorkerCheckOutcome::Passed, $detail);
    }

    public static function failed(string $name, string $detail, string $token = ''): self
    {
        return new self($name, WorkerCheckOutcome::Failed, self::safe($detail, $token));
    }

    /**
     * @param  string  $reason  why this was never asked — a fact about the deployment
     */
    public static function skipped(string $name, string $reason): self
    {
        return new self($name, WorkerCheckOutcome::Skipped, $reason);
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

    private static function safe(string $detail, string $token): ?string
    {
        $masked = $token === ''
            ? $detail
            : (new CredentialRedactor([$token]))->redact($detail) ?? $detail;

        return CredentialRedactor::scrub($masked);
    }
}
