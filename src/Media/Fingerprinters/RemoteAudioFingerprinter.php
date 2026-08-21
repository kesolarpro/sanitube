<?php

declare(strict_types=1);

namespace SaniTube\Media\Fingerprinters;

use SaniTube\Media\Analyzers\RemoteAudioAnalyzer;
use SaniTube\Media\Contracts\AudioFingerprinter;
use SaniTube\Media\Contracts\FingerprintsStoredObject;
use SaniTube\Media\Exceptions\FingerprintFailed;
use SaniTube\Media\FingerprintResult;
use SaniTube\Worker\Client\WorkerClient;
use SaniTube\Worker\Enums\WorkerCapability;
use SaniTube\Worker\WorkerJobFailed;

/**
 * Fingerprints an object by asking a worker to.
 *
 * See {@see RemoteAudioAnalyzer} — same shape, same
 * reasoning. The worker **measures and returns**; it decides nothing. Whether
 * two recordings are the same, whether a duplicate is trashed, what a threshold
 * is: all of that stays in Core, where the evidence is read.
 */
final readonly class RemoteAudioFingerprinter implements AudioFingerprinter, FingerprintsStoredObject
{
    public function __construct(private WorkerClient $worker) {}

    public function name(): string
    {
        return 'chromaprint';
    }

    public function version(): string
    {
        $reported = $this->worker->identity()?->tools['fpcalc'] ?? null;

        return is_string($reported) && trim($reported) !== '' ? mb_substr(trim($reported), 0, 64) : 'remote';
    }

    public function isAvailable(): bool
    {
        return $this->worker->can(WorkerCapability::AudioFingerprint);
    }

    public function fingerprintStored(string $storageKey): FingerprintResult
    {
        try {
            $answer = $this->worker->run(WorkerCapability::AudioFingerprint, ['storage_key' => $storageKey]);
        } catch (WorkerJobFailed $failure) {
            throw FingerprintFailed::because($failure->reason);
        }

        $fingerprint = $answer['fingerprint'] ?? null;
        $duration = $answer['duration_seconds'] ?? null;

        if (! is_string($fingerprint) || trim($fingerprint) === '' || ! is_int($duration)) {
            // A worker that answered in a shape this build does not understand.
            // A structured failure, never a TypeError inside a queue worker.
            throw FingerprintFailed::because('WORKER_MALFORMED_RESPONSE');
        }

        return new FingerprintResult(
            fingerprint: $fingerprint,
            durationSeconds: $duration,
            algorithm: is_string($answer['algorithm'] ?? null) ? $answer['algorithm'] : 'chromaprint',
        );
    }

    public function fingerprint(string $localPath): FingerprintResult
    {
        throw FingerprintFailed::because('REMOTE_FINGERPRINTER_NEEDS_A_STORED_OBJECT');
    }
}
