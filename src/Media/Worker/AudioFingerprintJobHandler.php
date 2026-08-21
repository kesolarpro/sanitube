<?php

declare(strict_types=1);

namespace SaniTube\Media\Worker;

use SaniTube\Media\Contracts\AudioFingerprinter;
use SaniTube\Media\Exceptions\FingerprintFailed;
use SaniTube\Media\FingerprintResult;
use SaniTube\Worker\Contracts\WorkerJobHandler;
use SaniTube\Worker\Enums\WorkerCapability;
use SaniTube\Worker\WorkerJobFailed;

/**
 * Taking an acoustic fingerprint, on the worker's machine.
 *
 * See {@see MediaProbeJobHandler}. It measures and returns; whether two
 * recordings are the same, and what follows if they are, stays in Core.
 */
final readonly class AudioFingerprintJobHandler implements WorkerJobHandler
{
    public function __construct(
        private AudioFingerprinter $fingerprinter,
        private StagedObject $objects,
    ) {}

    public function capability(): WorkerCapability
    {
        return WorkerCapability::AudioFingerprint;
    }

    public function isRunnable(): bool
    {
        return $this->fingerprinter->isAvailable();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['storage_key' => ['required', 'string', 'max:1024']];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        $result = $this->objects->withLocalCopy(
            (string) ($payload['storage_key'] ?? ''),
            function (string $path): FingerprintResult {
                try {
                    return $this->fingerprinter->fingerprint($path);
                } catch (FingerprintFailed $failure) {
                    // The tool's own reason, which is already a code from a
                    // closed set this repository defines.
                    throw WorkerJobFailed::because($failure->reason);
                }
            },
        );

        return [
            'fingerprint' => $result->fingerprint,
            'duration_seconds' => $result->durationSeconds,
            'algorithm' => $result->algorithm,
            'tool' => $this->fingerprinter->name(),
            'tool_version' => $this->fingerprinter->version(),
        ];
    }
}
