<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Services;

use SaniTube\Localization\ContentLanguage;
use SaniTube\MusicGeneration\Enums\CommercialRightsStatus;
use SaniTube\MusicGeneration\Enums\GenerationCapability;
use SaniTube\MusicGeneration\Enums\MusicGenerationStatus;
use SaniTube\MusicGeneration\Exceptions\GenerationException;
use SaniTube\MusicGeneration\Models\GenerationProject;
use SaniTube\MusicGeneration\Models\MusicGeneration;

/**
 * Records the intent to generate, and queues the submission.
 *
 * The row exists before the provider is called, and that ordering is the same
 * one ING-001 uses for assets: everything after this point is resumable
 * precisely because the generation — and therefore what a provider job will be
 * attached to — is already written down. A submit that crashes mid-flight
 * leaves a QUEUED row a retry can pick up, not a provider job nobody owns.
 *
 * **Both brakes are checked here as well as at submission**, because the refusal
 * an operator can act on is the one that arrives before a row exists.
 *
 * Which supplier is asked is decided here too, and decided rather than assumed:
 * {@see SelectGenerationProvider} matches what the request needs against what
 * each configured provider can do. The circuit breaker moved in there with it,
 * because a failing provider should first be *avoided* and only refused when
 * there is nowhere else to go.
 *
 * This check is a courtesy, not the bound, and the difference is worth stating
 * plainly: the ceiling counts requests that *left this server*, and starting
 * reaches nobody. So a hundred generations started in one breath all pass this
 * check — it fires only once earlier **submissions** have filled the window.
 * {@see SubmitMusicGeneration} holds the real bound, because that is where the
 * request leaves, and a queue can run hours after this did with a thousand
 * siblings ahead of it.
 *
 * Counting starts instead would be worse in the direction that matters: a
 * generation refused before submission costs nothing, and letting it consume
 * the ceiling would let a failed run lock out a working one.
 *
 * Commercial rights are UNKNOWN. Always, at this point: a generation that has
 * not happened cannot have been cleared for sale, and defaulting to anything
 * else would put an unlicensed recording in front of a distributor.
 */
final readonly class StartMusicGeneration
{
    public function __construct(
        private SelectGenerationProvider $selection,
        private GenerationSpendGuard $ceiling,
    ) {}

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
        // Chosen, not assumed. What the request needs decides which suppliers
        // can serve it; a named one is honoured or refused, never replaced.
        $provider = $this->selection->select(
            required: self::requires($lyrics, $instrumental),
            preferred: $providerName,
        );

        if ($project instanceof GenerationProject && ! $project->status->acceptsGenerations()) {
            throw GenerationException::projectClosed($project->uuid);
        }

        // Refused before the row exists, so nothing is created that will only
        // fail later. A generation nobody can submit is worse than a refusal:
        // it sits QUEUED looking like work in progress.
        $exhausted = $this->ceiling->exhaustedWindow();

        if ($exhausted !== null) {
            throw GenerationException::ceilingReached($exhausted);
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

    /**
     * What this request needs a supplier to be able to do.
     *
     * Derived from the request rather than configured, because the answer is
     * already in what was asked for: words to sing need a supplier that sings
     * supplied words, and a request for no vocal needs one that honours that
     * rather than adding one anyway.
     *
     * Lyrics on an instrumental are not a requirement, because they are not a
     * request -- {@see handle()} drops them, for the same reason
     * {@see language()} overrides the language.
     *
     * @return list<GenerationCapability>
     */
    public static function requires(?string $lyrics, bool $instrumental): array
    {
        $required = [GenerationCapability::TextToMusic];

        if ($instrumental) {
            $required[] = GenerationCapability::Instrumental;

            return $required;
        }

        if ($lyrics !== null && trim($lyrics) !== '') {
            $required[] = GenerationCapability::LyricsToMusic;
        }

        return $required;
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
