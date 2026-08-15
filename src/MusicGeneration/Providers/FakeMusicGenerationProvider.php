<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Providers;

use SaniTube\MusicGeneration\Contracts\MusicGenerationProvider;
use SaniTube\MusicGeneration\GenerationRequest;
use SaniTube\MusicGeneration\GenerationResult;
use SaniTube\MusicGeneration\GenerationStatus;
use SaniTube\MusicGeneration\ProviderResult;

/**
 * An in-memory generation provider.
 *
 * This is what keeps the Studio, the campaign engine and the ingestion
 * pipeline developable and testable while no external music API is available:
 * the rest of the platform is written against the contract, and swapping in a
 * real adapter changes one configuration value.
 *
 * It models the asynchronous lifecycle honestly — a generation starts queued
 * and only advances when it is advanced — so callers cannot get away with
 * assuming a synchronous provider. Every state a real provider can be in is
 * reachable here: queued, processing, completed, failed, cancelled; and a
 * completed job can carry one variant, two, or twenty.
 *
 * No network, no sleeping, no clock. A test that had to wait would be a test
 * nobody runs.
 */
final class FakeMusicGenerationProvider implements MusicGenerationProvider
{
    /** @var array<string, GenerationResult> */
    private array $jobs = [];

    /** @var array<string, list<ProviderResult>> */
    private array $results = [];

    private int $sequence = 0;

    /** How many variants the next completion produces, unless told otherwise. */
    private int $resultsPerJob = 1;

    public function __construct(
        private readonly string $name = 'fake',
        private bool $available = true,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function createGeneration(GenerationRequest $request): GenerationResult
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

    public function getGenerationStatus(string $providerJobId): GenerationResult
    {
        return $this->jobs[$providerJobId] ?? new GenerationResult(
            provider: $this->name,
            providerJobId: $providerJobId,
            status: GenerationStatus::Failed,
            failureReason: 'Unknown job.',
        );
    }

    public function fetchResults(string $providerJobId): array
    {
        // Empty until the job finishes, which is what a real provider does and
        // what stops a caller assuming results are available at submit time.
        return $this->results[$providerJobId] ?? [];
    }

    public function cancelGeneration(string $providerJobId): bool
    {
        $job = $this->jobs[$providerJobId] ?? null;

        if ($job === null || $job->isTerminal()) {
            return false;
        }

        $this->jobs[$providerJobId] = $this->transition($job, GenerationStatus::Cancelled);

        return true;
    }

    // ------------------------------------------------------------- test seams

    /**
     * Behave like a provider with no credentials.
     */
    public function unavailable(): self
    {
        $this->available = false;

        return $this;
    }

    /**
     * How many variants the next completion produces.
     */
    public function producing(int $results): self
    {
        $this->resultsPerJob = max(1, $results);

        return $this;
    }

    /**
     * Move a queued job to running, as a real provider would between polls.
     */
    public function startProcessing(string $providerJobId): GenerationResult
    {
        return $this->jobs[$providerJobId] = $this->transition(
            $this->getGenerationStatus($providerJobId),
            GenerationStatus::Running,
        );
    }

    /**
     * Finish a job and give it its variants.
     */
    public function complete(string $providerJobId, int $durationSeconds = 180): GenerationResult
    {
        $job = $this->getGenerationStatus($providerJobId);
        $variants = [];

        foreach (range(1, $this->resultsPerJob) as $index) {
            $variants[] = new ProviderResult(
                providerResultId: sprintf('%s-take-%d', $providerJobId, $index),
                sourceReference: sprintf('%s/take-%d.mp3', $providerJobId, $index),
                title: $job->title === null ? null : sprintf('%s (take %d)', $job->title, $index),
                durationMs: $durationSeconds * 1000,
                raw: ['take' => $index],
            );
        }

        $this->results[$providerJobId] = $variants;

        return $this->jobs[$providerJobId] = new GenerationResult(
            provider: $job->provider,
            providerJobId: $job->providerJobId,
            status: GenerationStatus::Completed,
            audioUrl: $variants[0]->sourceReference,
            title: $job->title,
            durationSeconds: $durationSeconds,
            model: $job->model,
            raw: $job->raw,
        );
    }

    /**
     * Fail a job the way a provider does: terminal, with a reason.
     */
    public function fail(string $providerJobId, string $reason): GenerationResult
    {
        $job = $this->getGenerationStatus($providerJobId);

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

    /**
     * A completed job that returned nothing to download. Rare, real, and the
     * case that would otherwise become an empty track in the catalogue.
     */
    public function completeWithNothing(string $providerJobId): GenerationResult
    {
        $this->results[$providerJobId] = [];

        $job = $this->getGenerationStatus($providerJobId);

        return $this->jobs[$providerJobId] = new GenerationResult(
            provider: $job->provider,
            providerJobId: $job->providerJobId,
            status: GenerationStatus::Completed,
            title: $job->title,
            model: $job->model,
            raw: $job->raw,
        );
    }

    private function transition(GenerationResult $job, GenerationStatus $status): GenerationResult
    {
        return new GenerationResult(
            provider: $job->provider,
            providerJobId: $job->providerJobId,
            status: $status,
            audioUrl: $job->audioUrl,
            title: $job->title,
            durationSeconds: $job->durationSeconds,
            model: $job->model,
            raw: $job->raw,
        );
    }
}
