<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Services;

use Illuminate\Support\Carbon;
use SaniTube\Localization\ContentLanguage;
use SaniTube\MusicGeneration\Enums\MusicGenerationStatus;
use SaniTube\MusicGeneration\GenerationRequest;
use SaniTube\MusicGeneration\Models\MusicGeneration;
use SaniTube\MusicGeneration\MusicGenerationManager;
use Throwable;

/**
 * Hands a queued generation to its provider.
 *
 * Idempotent by the same mechanism the rest of the platform uses: a generation
 * that already carries a `provider_job_id` has been submitted, and a redelivered
 * job returns without calling the provider again. Submitting twice would cost
 * a second billed generation and leave a job identifier nobody is tracking.
 */
final readonly class SubmitMusicGeneration
{
    public function __construct(private MusicGenerationManager $providers) {}

    public function handle(MusicGeneration $generation): MusicGeneration
    {
        if ($generation->provider_job_id !== null || $generation->status->isTerminal()) {
            // Already submitted, or cancelled before a worker got to it.
            return $generation;
        }

        $provider = $this->providers->provider($generation->provider);

        if (! $provider->isAvailable()) {
            return $this->fail($generation, 'The provider is not configured on this installation.');
        }

        try {
            $result = $provider->createGeneration(new GenerationRequest(
                prompt: $generation->prompt,
                stylePrompt: $generation->style_prompt,
                lyrics: $generation->lyrics,
                instrumental: $generation->instrumental,
                language: $generation->language_code === ContentLanguage::UNKNOWN
                    ? null
                    : ContentLanguage::fromCode($generation->language_code),
                model: $generation->model,
                parameters: $this->scalarParameters($generation),
            ));
        } catch (Throwable $exception) {
            // A provider outage is an ordinary condition for an optional
            // feature. The generation fails; the worker does not.
            return $this->fail($generation, $exception->getMessage());
        }

        if ($result->providerJobId === '') {
            return $this->fail($generation, $result->failureReason ?? 'The provider returned no job identifier.');
        }

        $generation->forceFill([
            'provider_job_id' => $result->providerJobId,
            'status' => MusicGenerationStatus::Processing,
            'model' => $result->model ?? $generation->model,
            'submitted_at' => Carbon::now(),
        ])->save();

        return $generation->refresh();
    }

    /**
     * @return array<string, scalar|null>
     */
    private function scalarParameters(MusicGeneration $generation): array
    {
        $parameters = [];

        foreach ($generation->parameters ?? [] as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $parameters[(string) $key] = $value;
            }
        }

        return $parameters;
    }

    private function fail(MusicGeneration $generation, string $reason): MusicGeneration
    {
        $generation->forceFill([
            'status' => MusicGenerationStatus::Failed,
            'failure_reason' => $reason,
            'completed_at' => Carbon::now(),
        ])->save();

        return $generation->refresh();
    }
}
