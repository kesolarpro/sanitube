<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Services;

use SaniTube\Localization\ContentLanguage;
use SaniTube\MusicGeneration\Enums\CommercialRightsStatus;
use SaniTube\MusicGeneration\Enums\MusicGenerationStatus;
use SaniTube\MusicGeneration\Exceptions\GenerationException;
use SaniTube\MusicGeneration\Models\GenerationProject;
use SaniTube\MusicGeneration\Models\MusicGeneration;
use SaniTube\MusicGeneration\MusicGenerationManager;

/**
 * Records the intent to generate, and queues the submission.
 *
 * The row exists before the provider is called, and that ordering is the same
 * one ING-001 uses for assets: everything after this point is resumable
 * precisely because the generation — and therefore what a provider job will be
 * attached to — is already written down. A submit that crashes mid-flight
 * leaves a QUEUED row a retry can pick up, not a provider job nobody owns.
 *
 * Commercial rights are UNKNOWN. Always, at this point: a generation that has
 * not happened cannot have been cleared for sale, and defaulting to anything
 * else would put an unlicensed recording in front of a distributor.
 */
final readonly class StartMusicGeneration
{
    public function __construct(private MusicGenerationManager $providers) {}

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function handle(
        string $prompt,
        ?GenerationProject $project = null,
        ?string $stylePrompt = null,
        ?string $lyrics = null,
        bool $instrumental = false,
        ?string $languageCode = null,
        ?string $model = null,
        array $parameters = [],
        ?string $providerName = null,
    ): MusicGeneration {
        $provider = $providerName === null
            ? $this->providers->default()
            : $this->providers->provider($providerName);

        if (! $provider->isAvailable()) {
            throw GenerationException::providerUnavailable($provider->name());
        }

        if ($project instanceof GenerationProject && ! $project->status->acceptsGenerations()) {
            throw GenerationException::projectClosed($project->uuid);
        }

        return MusicGeneration::query()->create([
            'project_id' => $project?->id,
            'provider' => $provider->name(),
            // Null until the provider answers. NULLs stay distinct inside the
            // unique index, so unlimited unsubmitted rows coexist.
            'provider_job_id' => null,
            'status' => MusicGenerationStatus::Queued,
            'prompt' => $prompt,
            'lyrics' => $instrumental ? null : $lyrics,
            'style_prompt' => $stylePrompt ?? $project?->default_style_prompt,
            'language_code' => $this->language($instrumental, $languageCode, $project),
            'instrumental' => $instrumental,
            'model' => $model,
            'parameters' => $parameters === [] ? null : $parameters,
            // Never inferred from the fact of a generation. See
            // CommercialRightsStatus.
            'commercial_rights_status' => CommercialRightsStatus::Unknown,
        ]);
    }

    private function language(bool $instrumental, ?string $requested, ?GenerationProject $project): string
    {
        // An instrumental has no linguistic content whatever prompt produced
        // it, and ISO 639-2 already has a code that says so. Deriving it here
        // keeps the same invariant CAT-001 enforces on promotion.
        if ($instrumental) {
            return ContentLanguage::INSTRUMENTAL;
        }

        $code = $requested ?? $project?->default_language;

        return $code === null ? ContentLanguage::UNKNOWN : ContentLanguage::fromCode($code)->code;
    }
}
