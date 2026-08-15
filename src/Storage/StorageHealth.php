<?php

declare(strict_types=1);

namespace SaniTube\Storage;

/**
 * What a provider could actually do when asked.
 *
 * Deliberately not a boolean. "Storage is down" and "storage accepts writes
 * but cannot delete" call for different responses, and an install whose
 * credentials are read-only should find that out from a diagnostic command
 * rather than from a master that never arrives.
 *
 * Failure details are redacted **here**, in the one constructor every failing
 * report goes through, rather than in each provider. A health report is the
 * likeliest place a misconfigured secret surfaces — it quotes whatever the SDK
 * said about a signed request — and it is printed to terminals, written to
 * cron mail and pasted into support threads. Leaving that to each
 * implementation means the next provider anyone writes leaks by default.
 */
final readonly class StorageHealth
{
    /**
     * @param  array<string, bool>  $checks  probe name => whether it succeeded
     */
    public function __construct(
        public string $provider,
        public bool $healthy,
        public array $checks,
        public bool $temporaryUrls = false,
        public ?string $detail = null,
    ) {}

    /**
     * @param  array<string, bool>  $checks
     */
    public static function passing(string $provider, array $checks, bool $temporaryUrls): self
    {
        return new self($provider, true, $checks, $temporaryUrls);
    }

    /**
     * @param  array<string, bool>  $checks
     */
    public static function failing(string $provider, array $checks, bool $temporaryUrls, string $detail): self
    {
        return new self(
            $provider,
            false,
            $checks,
            $temporaryUrls,
            CredentialRedactor::fromDiskConfiguration()->redact($detail),
        );
    }

    /**
     * @return list<string>
     */
    public function failedChecks(): array
    {
        return array_keys(array_filter($this->checks, static fn (bool $passed): bool => ! $passed));
    }

    /**
     * @return array{provider: string, healthy: bool, checks: array<string, bool>, temporary_urls: bool, detail: string|null}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'healthy' => $this->healthy,
            'checks' => $this->checks,
            'temporary_urls' => $this->temporaryUrls,
            'detail' => $this->detail,
        ];
    }
}
