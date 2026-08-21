<?php

declare(strict_types=1);

namespace SaniTube\Media\Analyzers;

use SaniTube\Media\AudioAnalysisResult;
use SaniTube\Media\Contracts\AnalyzesStoredObject;
use SaniTube\Media\Contracts\AudioAnalyzer;
use SaniTube\Worker\Client\WorkerClient;
use SaniTube\Worker\Enums\WorkerCapability;
use SaniTube\Worker\WorkerJobFailed;

/**
 * Probes an object by asking a worker to.
 *
 * Same contract, same result, different machine — which is the point. Nothing
 * in the analysis service knows a network was involved.
 *
 * **It takes a storage key, not a path.** A worker reads the object from the
 * storage both sides share, so Core never streams the bytes at all. The
 * `analyze(string $localPath)` road still exists because the interface promises
 * it, and it is the slow one: a local file has to be uploaded before anybody
 * else can read it, so it is used only when a caller genuinely has nothing but
 * a path.
 *
 * `name()` is the **tool**, not the machine. Two installations probing the same
 * file with ffprobe should record the same analyser, whichever host ran it —
 * the evidence is about what measured the audio, not about where the process
 * lived. The version comes from the worker's handshake, so a worker on a newer
 * build is visible in the row rather than silently disagreeing with local
 * results.
 */
final readonly class RemoteAudioAnalyzer implements AnalyzesStoredObject, AudioAnalyzer
{
    public function __construct(private WorkerClient $worker) {}

    public function name(): string
    {
        return 'ffprobe';
    }

    public function version(): string
    {
        // What the worker says it is running. Recorded beside every analysis,
        // so `alreadyAnalyzed()` re-measures when a worker is upgraded --
        // which is correct: a different build can report different facts.
        $reported = $this->worker->identity()?->tools['ffprobe'] ?? null;

        return is_string($reported) && trim($reported) !== '' ? mb_substr(trim($reported), 0, 64) : 'remote';
    }

    /**
     * Asked, never assumed.
     *
     * A configured URL and token prove somebody typed two settings. This asks
     * the worker whether it does this work, through a cached handshake.
     */
    public function isAvailable(): bool
    {
        return $this->worker->can(WorkerCapability::MediaProbe);
    }

    public function analyzeStored(string $storageKey): AudioAnalysisResult
    {
        try {
            $answer = $this->worker->run(WorkerCapability::MediaProbe, ['storage_key' => $storageKey]);
        } catch (WorkerJobFailed $failure) {
            // A refusal code from a closed set, never a client's own words.
            // The analysis fails; the caller does not.
            return AudioAnalysisResult::failed($failure->reason);
        }

        return AudioAnalysisResult::fromArray($answer);
    }

    /**
     * The slow road, kept because the interface promises it.
     *
     * A worker cannot read a file on Core's disk, so this would have to upload
     * it first — and every caller in this platform has a storage key, so
     * nothing does. Refused rather than implemented badly: an upload path that
     * exists but is never exercised is one that breaks silently.
     */
    public function analyze(string $localPath): AudioAnalysisResult
    {
        return AudioAnalysisResult::failed(
            'A remote analyser reads objects from shared storage, not files on this machine.',
        );
    }
}
