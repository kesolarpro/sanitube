<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Services;

use SaniTube\MusicGeneration\Enums\GenerationProjectStatus;
use SaniTube\MusicGeneration\Models\GenerationProject;

/**
 * Starts a campaign.
 *
 * **Extracted, not invented.** This lived inside
 * `GenerationProjectController::store()`. Moving it here so a second surface
 * can create a project without a second copy of the one rule that matters:
 *
 * **A project starts as a DRAFT.** Nothing has been generated yet, and ACTIVE
 * would claim otherwise — which matters because `acceptsGenerations()` reads
 * the status, and a campaign's whole purpose is to be able to answer "how many
 * did that produce".
 *
 * Defaults are stored as *defaults*, never applied here. `StartMusicGeneration`
 * reads them when a generation omits its own, which keeps one answer to "where
 * did this style prompt come from".
 */
final readonly class CreateGenerationProject
{
    public function handle(
        string $name,
        ?int $targetTrackCount = null,
        ?string $defaultLanguage = null,
        ?string $defaultGenre = null,
        ?string $defaultStylePrompt = null,
        ?int $createdBy = null,
    ): GenerationProject {
        return GenerationProject::query()->create([
            'name' => trim($name),
            'target_track_count' => $targetTrackCount,
            'default_language' => $this->text($defaultLanguage),
            'default_genre' => $this->text($defaultGenre),
            'default_style_prompt' => $this->text($defaultStylePrompt),
            'created_by' => $createdBy,
            'status' => GenerationProjectStatus::Draft,
        ]);
    }

    /**
     * An empty box is "nothing supplied", not "the default is the empty
     * string" — which would then be inherited by every generation in the
     * campaign as a style prompt of nothing.
     */
    private function text(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return trim($value) === '' ? null : trim($value);
    }
}
