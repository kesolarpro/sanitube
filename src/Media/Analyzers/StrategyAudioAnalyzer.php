<?php

declare(strict_types=1);

namespace SaniTube\Media\Analyzers;

use SaniTube\Media\AudioAnalysisResult;
use SaniTube\Media\Contracts\AnalyzesStoredObject;
use SaniTube\Media\Contracts\AudioAnalyzer;
use SaniTube\Media\Execution\ExecutionPlace;
use SaniTube\Media\Execution\MediaExecution;
use SaniTube\Media\Services\AnalyzeAsset;

/**
 * The analyser every caller actually holds.
 *
 * It decides, per call, whether the work runs here or on a worker, and then
 * gets out of the way. **No caller knows which happened**, which is the
 * property WRK-002 exists to establish: `AnalyzeAsset` is unchanged except that
 * it now offers a storage key when the analyser can use one.
 *
 * `name()` and `version()` are asked *after* the work, and they answer for
 * whichever machine did it. That matters because they are persisted as
 * evidence and used to decide whether an asset needs re-analysing: a worker
 * running a newer ffprobe than this host is a real difference, and a row that
 * claimed otherwise would hide it.
 */
final readonly class StrategyAudioAnalyzer implements AnalyzesStoredObject, AudioAnalyzer
{
    public function __construct(
        private MediaExecution $execution,
        private AudioAnalyzer $local,
        private RemoteAudioAnalyzer $remote,
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

    public function analyzeStored(string $storageKey): AudioAnalysisResult
    {
        $chosen = $this->chosen();

        if ($chosen instanceof AnalyzesStoredObject) {
            return $chosen->analyzeStored($storageKey);
        }

        // A local analyser needs a local file, and the caller has already been
        // told to hand over a key. Reporting a failure here rather than
        // inventing a download keeps one place responsible for streaming.
        return AudioAnalysisResult::failed('LOCAL_ANALYSER_NEEDS_A_FILE');
    }

    public function analyze(string $localPath): AudioAnalysisResult
    {
        return $this->chosen()->analyze($localPath);
    }

    /**
     * Whether the chosen path can work from a storage key.
     *
     * Asked by {@see AnalyzeAsset} before it streams
     * an object to a temporary file: when the answer is yes, that copy is
     * skipped entirely and the bytes never touch Core's disk.
     */
    public function prefersStoredObject(): bool
    {
        return $this->chosen() instanceof AnalyzesStoredObject;
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

    private function chosen(): AudioAnalyzer
    {
        return $this->place() === ExecutionPlace::RemoteWorker ? $this->remote : $this->local;
    }
}
