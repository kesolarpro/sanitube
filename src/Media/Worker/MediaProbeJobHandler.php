<?php

declare(strict_types=1);

namespace SaniTube\Media\Worker;

use SaniTube\Media\AudioAnalysisResult;
use SaniTube\Media\Contracts\AudioAnalyzer;
use SaniTube\Worker\Contracts\WorkerJobHandler;
use SaniTube\Worker\Enums\WorkerCapability;

/**
 * Reading a file's technical facts, on the worker's machine.
 *
 * The second capability on the boundary WRK-001 generalised, and the first that
 * is not generation — which is the point: adding it needed a handler and one
 * registration, and changed nothing about the protocol.
 *
 * **It measures and returns.** No asset is touched, no analysis row is written,
 * nothing is decided. Core persists the evidence exactly as it does for a local
 * probe, and remains the only thing that holds catalogue truth.
 */
final readonly class MediaProbeJobHandler implements WorkerJobHandler
{
    public function __construct(
        private AudioAnalyzer $analyzer,
        private StagedObject $objects,
    ) {}

    public function capability(): WorkerCapability
    {
        return WorkerCapability::MediaProbe;
    }

    /**
     * Cheap: whether this machine has the tool. Never a probe of a file to
     * find out.
     */
    public function isRunnable(): bool
    {
        return $this->analyzer->isAvailable();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // A storage key and nothing else. No path, no tool selection, no
        // timeout: every one of those is this worker's own decision, and a
        // field for it would be a field a caller could set.
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
            fn (string $path): AudioAnalysisResult => $this->analyzer->analyze($path),
        );

        return [
            'succeeded' => $result->succeeded,
            'duration_ms' => $result->durationMs,
            'codec' => $result->codec,
            'container' => $result->container,
            'sample_rate' => $result->sampleRate,
            'bit_depth' => $result->bitDepth,
            'channels' => $result->channels,
            'bitrate' => $result->bitrate,
            // The tool that measured, so the row Core writes says what actually
            // produced it rather than which machine was asked.
            'analyzer' => $this->analyzer->name(),
            'analyzer_version' => $this->analyzer->version(),
            'failure_message' => $result->failureMessage,
        ];
    }
}
