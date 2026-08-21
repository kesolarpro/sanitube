<?php

declare(strict_types=1);

namespace SaniTube\Storage\Certification;

/**
 * What one provider proved it could do, against the real service.
 *
 * **Certified means every check that ran passed, and at least one ran.** A
 * report of nothing but skips is not a certification — it is a provider that
 * was never asked anything, and calling that green is how a platform ends up
 * declaring an unconfigured install production-ready.
 */
final readonly class CertificationReport
{
    /**
     * @param  list<CertificationCheck>  $checks
     */
    public function __construct(
        public string $provider,
        public array $checks,
    ) {}

    public function certified(): bool
    {
        $ran = 0;

        foreach ($this->checks as $check) {
            if ($check->outcome === CertificationOutcome::Failed) {
                return false;
            }

            if ($check->outcome === CertificationOutcome::Passed) {
                $ran++;
            }
        }

        return $ran > 0;
    }

    /**
     * @return list<CertificationCheck>
     */
    public function failures(): array
    {
        return array_values(array_filter(
            $this->checks,
            static fn (CertificationCheck $check): bool => $check->outcome === CertificationOutcome::Failed,
        ));
    }

    /**
     * @return list<CertificationCheck>
     */
    public function skipped(): array
    {
        return array_values(array_filter(
            $this->checks,
            static fn (CertificationCheck $check): bool => $check->outcome === CertificationOutcome::Skipped,
        ));
    }

    /**
     * @return array{provider: string, certified: bool, checks: list<array{name: string, outcome: string, detail: string|null}>}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'certified' => $this->certified(),
            'checks' => array_map(
                static fn (CertificationCheck $check): array => $check->toArray(),
                $this->checks,
            ),
        ];
    }
}
