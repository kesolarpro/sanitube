<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Providers;

use SaniTube\MusicGeneration\Contracts\MusicGenerationProvider;
use SaniTube\MusicGeneration\GenerationRequest;
use SaniTube\MusicGeneration\GenerationResult;
use SaniTube\MusicGeneration\GenerationStatus;

/**
 * An in-memory generation provider.
 *
 * This is what keeps the Studio, the campaign engine and the ingestion
 * pipeline developable and testable while no external music API is available:
 * the rest of the platform is written against the contract, and swapping in a
 * real adapter changes one configuration value.
 *
 * It models the asynchronous lifecycle honestly — a generation starts pending
 * and only becomes complete when it is advanced — so callers cannot get away
 * with assuming a synchronous provider.
 */
final class FakeMusicGenerationProvider implements MusicGenerationProvider
{
    /** @var array<string, GenerationResult> */
    private array $jobs = [];

    private int $sequence = 0;

    public function __construct(
        private readonly string $name = 'fake',
        private readonly bool $available = true,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function generate(GenerationRequest $request): GenerationResult
    {
        $jobId = sprintf('fake-%03d', ++$this->sequence);

        return $this->jobs[$jobId] = new GenerationResult(
            provider: $this->name,
            providerJobId: $jobId,
            status: GenerationStatus::Pending,
            title: $request->prompt,
            model: $request->model ?? 'fake-v1',
            raw: ['prompt' => $request->prompt, 'instrumental' => $request->instrumental],
        );
    }

    public function status(string $providerJobId): GenerationResult
    {
        return $this->jobs[$providerJobId] ?? new GenerationResult(
            provider: $this->name,
            providerJobId: $providerJobId,
            status: GenerationStatus::Failed,
            failureReason: 'Unknown job.',
        );
    }

    public function cancel(string $providerJobId): bool
    {
        $job = $this->jobs[$providerJobId] ?? null;

        if ($job === null || $job->isTerminal()) {
            return false;
        }

        $this->jobs[$providerJobId] = new GenerationResult(
            provider: $job->provider,
            providerJobId: $job->providerJobId,
            status: GenerationStatus::Cancelled,
            title: $job->title,
            model: $job->model,
            raw: $job->raw,
        );

        return true;
    }

    /**
     * Test helper: move a pending job to completion.
     */
    public function complete(string $providerJobId, string $audioUrl, int $durationSeconds = 180): GenerationResult
    {
        $job = $this->status($providerJobId);

        return $this->jobs[$providerJobId] = new GenerationResult(
            provider: $job->provider,
            providerJobId: $job->providerJobId,
            status: GenerationStatus::Completed,
            audioUrl: $audioUrl,
            title: $job->title,
            durationSeconds: $durationSeconds,
            model: $job->model,
            raw: $job->raw,
        );
    }

    /**
     * Test helper: fail a pending job.
     */
    public function fail(string $providerJobId, string $reason): GenerationResult
    {
        $job = $this->status($providerJobId);

        return $this->jobs[$providerJobId] = new GenerationResult(
            provider: $job->provider,
            providerJobId: $job->providerJobId,
            status: GenerationStatus::Failed,
            title: $job->title,
            model: $job->model,
            failureReason: $reason,
            raw: $job->raw,
        );
    }
}
