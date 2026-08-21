<?php

declare(strict_types=1);

namespace SaniTube\Media\Fingerprinters;

use SaniTube\Media\Analyzers\StrategyAudioAnalyzer;
use SaniTube\Media\Contracts\AudioFingerprinter;
use SaniTube\Media\Contracts\FingerprintsStoredObject;
use SaniTube\Media\Exceptions\FingerprintFailed;
use SaniTube\Media\Execution\ExecutionPlace;
use SaniTube\Media\Execution\MediaExecution;
use SaniTube\Media\FingerprintResult;

/**
 * The fingerprinter every caller actually holds.
 *
 * See {@see StrategyAudioAnalyzer} — same shape, same
 * reasoning. Measurement moves; judgement does not. Whichever machine computes
 * the fingerprint, Core is the only thing that decides what it means.
 */
final readonly class StrategyAudioFingerprinter implements AudioFingerprinter, FingerprintsStoredObject
{
    public function __construct(
        private MediaExecution $execution,
        private AudioFingerprinter $local,
        private RemoteAudioFingerprinter $remote,
    ) {}

    public function name(): string
    {
        return $this->chosen()->name();
    }

    public function version(): string
    {
        return $this->chosen()->version();
    }

    public function isAvailable(): bool
    {
        return $this->place() !== ExecutionPlace::Nowhere;
    }

    public function fingerprintStored(string $storageKey): FingerprintResult
    {
        $chosen = $this->chosen();

        if ($chosen instanceof FingerprintsStoredObject) {
            return $chosen->fingerprintStored($storageKey);
        }

        throw FingerprintFailed::because('LOCAL_FINGERPRINTER_NEEDS_A_FILE');
    }

    public function fingerprint(string $localPath): FingerprintResult
    {
        return $this->chosen()->fingerprint($localPath);
    }

    public function prefersStoredObject(): bool
    {
        return $this->chosen() instanceof FingerprintsStoredObject;
    }

    public function place(): ExecutionPlace
    {
        return $this->execution->placeFor(
            // Each side answers for itself. The remote one asks the worker's
            // cached handshake; the local one looks for a binary.
            fn (): bool => $this->remote->isAvailable(),
            fn (): bool => $this->local->isAvailable(),
        );
    }

    private function chosen(): AudioFingerprinter
    {
        return $this->place() === ExecutionPlace::RemoteWorker ? $this->remote : $this->local;
    }
}
