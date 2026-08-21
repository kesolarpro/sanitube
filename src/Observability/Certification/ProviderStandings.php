<?php

declare(strict_types=1);

namespace SaniTube\Observability\Certification;

/**
 * Every external provider this platform can use, and where each one stands
 * on this installation — derived from configuration and the certification
 * ledger, never asserted by hand.
 *
 * The assessor asks two questions per area: *is it configured here* (from
 * config, treating `none` and an empty credential as the same honest no),
 * and *has this exact configuration passed a real certification run* (from
 * the ledger, fingerprint-matched). The two statuses that describe the
 * world rather than the installation — Suno's absent API, DDEX's
 * unreachable documentation — are stated with their reasons and cannot be
 * configured away.
 */
final readonly class ProviderStandings
{
    public function __construct(private CertificationLedger $ledger) {}

    /**
     * @return list<ProviderStanding>
     */
    public function assess(): array
    {
        return [
            $this->objectStorage(),
            $this->worker(),
            $this->enrichmentAi(),
            $this->transcription(),
            $this->artwork(),
            $this->musicGeneration(),
            $this->distributor(),
            $this->ddex(),
        ];
    }

    /**
     * Identity fields only — the fingerprint says *which* configuration was
     * certified, and a fingerprint that could leak a secret would turn the
     * ledger file into a credential store.
     *
     * @param  list<string|null>  $identity
     */
    public static function fingerprint(array $identity): string
    {
        return substr(hash('sha256', implode('|', array_map(static fn (?string $v): string => (string) $v, $identity))), 0, 16);
    }

    private function objectStorage(): ProviderStanding
    {
        $name = (string) config('storage.default', 'local');
        $driver = (string) config('storage.providers.'.$name.'.driver', '');

        if ($driver === 'local' || $driver === '') {
            return new ProviderStanding(
                'object_storage',
                'Object storage (S3/R2)',
                CertificationStatus::NotConfigured,
                'The local disk is canonical. Configure an S3-compatible provider and certify it with '
                    .'sanitube:storage:check --certify.',
            );
        }

        return $this->fromLedger(
            'object_storage',
            'Object storage (S3/R2)',
            'storage:'.$name,
            self::storageFingerprint($name),
            sprintf('[%s] is configured. sanitube:storage:check --certify proves it against the real service.', $name),
            sprintf('[%s] passed a real certification run.', $name),
        );
    }

    public static function storageFingerprint(string $name): string
    {
        $disk = (string) config('storage.providers.'.$name.'.disk', $name);

        return self::fingerprint([
            (string) config('storage.providers.'.$name.'.driver'),
            $disk,
            (string) config('filesystems.disks.'.$disk.'.bucket'),
            (string) config('filesystems.disks.'.$disk.'.endpoint'),
            (string) config('filesystems.disks.'.$disk.'.region'),
        ]);
    }

    private function worker(): ProviderStanding
    {
        if (trim((string) config('worker.url')) === '' || trim((string) config('worker.token')) === '') {
            return new ProviderStanding(
                'worker',
                'Remote worker',
                CertificationStatus::NotConfigured,
                'No worker is configured, which is the ordinary case: everything that can run locally still does.',
            );
        }

        return $this->fromLedger(
            'worker',
            'Remote worker',
            'worker',
            self::workerFingerprint(),
            'A worker is configured. sanitube:worker:check certifies the handshake, capabilities, refusals and token.',
            'The worker passed a real certification run.',
        );
    }

    public static function workerFingerprint(): string
    {
        // The URL is identity here, hashed and never printed; the token is
        // exactly what must not enter a fingerprint.
        return self::fingerprint([(string) config('worker.url')]);
    }

    private function enrichmentAi(): ProviderStanding
    {
        return $this->keyedProvider(
            key: 'ai',
            label: 'AI enrichment (OpenAI / Claude)',
            provider: (string) config('ai.default', 'none'),
            apiKey: (string) config('ai.providers.'.(string) config('ai.default', 'none').'.key'),
            certifyHint: 'A real call is operator-triggered and cost-aware; the fake provider exercises every path without one.',
        );
    }

    private function transcription(): ProviderStanding
    {
        return $this->keyedProvider(
            key: 'transcription',
            label: 'Transcription',
            provider: (string) config('transcription.provider', 'none'),
            apiKey: (string) config('transcription.providers.'.(string) config('transcription.provider', 'none').'.key'),
            certifyHint: 'Certification is a real transcription of a short file, operator-triggered.',
        );
    }

    private function artwork(): ProviderStanding
    {
        return $this->keyedProvider(
            key: 'artwork',
            label: 'Artwork generation',
            provider: (string) config('artwork.default_provider', 'none'),
            apiKey: (string) config('artwork.providers.'.(string) config('artwork.default_provider', 'none').'.key'),
            certifyHint: 'Certification is one real image, operator-triggered and paid for knowingly.',
        );
    }

    private function musicGeneration(): ProviderStanding
    {
        $provider = (string) config('generation.default', 'none');

        if ($provider === 'none') {
            return new ProviderStanding(
                'music_generation',
                'Music generation',
                CertificationStatus::Unavailable,
                'No real provider publishes an API contract to target (GEN-002); reverse-engineered '
                    .'wrappers are permanently excluded (ADR-0018). Generation through a self-hosted '
                    .'worker is the worker\'s capability, certified there.',
            );
        }

        if ($provider === 'fake') {
            return new ProviderStanding(
                'music_generation',
                'Music generation',
                CertificationStatus::CodeReady,
                'The fake provider exercises every path without a network. It certifies nothing.',
            );
        }

        return $this->fromLedger(
            'music_generation',
            'Music generation',
            'generation:'.$provider,
            self::fingerprint([$provider]),
            sprintf('[%s] is configured and has not passed a real generation run.', $provider),
            sprintf('[%s] passed a real generation run.', $provider),
        );
    }

    private function distributor(): ProviderStanding
    {
        $provider = (string) config('distribution.default', 'none');

        if ($provider === 'none' || $provider === 'fake') {
            return new ProviderStanding(
                'distributor',
                'Distributor',
                CertificationStatus::CodeReady,
                'The delivery boundary (ReleasePackage) and the manual export path are complete; a real '
                    .'distributor is an onboarding, not a build. The fake distributor holds the contract '
                    .'in the meantime.',
            );
        }

        return $this->fromLedger(
            'distributor',
            'Distributor',
            'distributor:'.$provider,
            self::fingerprint([$provider]),
            sprintf('[%s] is configured. Certify against its sandbox before anything irreversible.', $provider),
            sprintf('[%s] passed a certification run.', $provider),
        );
    }

    private function ddex(): ProviderStanding
    {
        return new ProviderStanding(
            'ddex',
            'DDEX delivery',
            CertificationStatus::BlockedExternal,
            'The authoritative specifications are unreachable from this environment, and a delivery '
                .'format written from memory would be an invented one. Manual provider-neutral export '
                .'remains supported.',
        );
    }

    private function keyedProvider(string $key, string $label, string $provider, string $apiKey, string $certifyHint): ProviderStanding
    {
        if ($provider === 'none' || trim($provider) === '') {
            return new ProviderStanding($key, $label, CertificationStatus::NotConfigured, 'No provider is configured.');
        }

        if (trim($apiKey) === '') {
            return new ProviderStanding(
                $key,
                $label,
                CertificationStatus::NotConfigured,
                sprintf('[%s] is named but carries no credential, which is not configured, only aspirational.', $provider),
            );
        }

        return $this->fromLedger(
            $key,
            $label,
            $key.':'.$provider,
            self::fingerprint([$provider]),
            sprintf('[%s] is configured. %s', $provider, $certifyHint),
            sprintf('[%s] passed a real call.', $provider),
        );
    }

    private function fromLedger(
        string $key,
        string $label,
        string $ledgerKey,
        string $fingerprint,
        string $uncertifiedDetail,
        string $certifiedDetail,
    ): ProviderStanding {
        $at = $this->ledger->certifiedAt($ledgerKey, $fingerprint);

        if ($at === null) {
            return new ProviderStanding($key, $label, CertificationStatus::ConfiguredUncertified, $uncertifiedDetail);
        }

        return new ProviderStanding($key, $label, CertificationStatus::Certified, $certifiedDetail, $at);
    }
}
