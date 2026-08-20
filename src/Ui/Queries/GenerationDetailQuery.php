<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use App\Models\User;
use SaniTube\MusicGeneration\Enums\MusicGenerationStatus;
use SaniTube\MusicGeneration\Models\MusicGeneration;
use SaniTube\MusicGeneration\Models\MusicGenerationResult;
use SaniTube\MusicGeneration\ProviderFailure;

/**
 * One generation, its inputs, and what came back.
 *
 * Three things are deliberately absent from the payload, and each of them is
 * the kind of field that gets added by accident later:
 *
 *   - **`provider_job_id`** — the handle the provider's API uses for this job.
 *   - **`results.source_reference`** — where the provider is serving the audio
 *     from. Providers routinely hand out time-limited signed URLs here, so it
 *     is a bearer credential in a column, and republishing it would hand out
 *     access SaniTube never checked.
 *   - **`results.raw`** — the provider's whole response, which contains both of
 *     the above and whatever else it felt like sending.
 *
 * What replaces the reference is `has_audio`: whether there is something to
 * import. A completed generation with no audio is a provider fault rather than
 * an empty track, and that distinction is what a person needs to see.
 *
 * Once a result has been imported it has an asset and a candidate, and those
 * are linked by uuid — which is where the audio is actually listened to, under
 * the preview rules UI-003d settled.
 */
final readonly class GenerationDetailQuery
{
    /**
     * @return array<string, mixed>
     */
    public function forGeneration(MusicGeneration $generation, User $viewer): array
    {
        $generation->load(['project', 'results']);

        return [
            'uuid' => $generation->uuid,
            'status' => $generation->status->value,
            'provider' => $generation->provider,
            'model' => $generation->model,

            'prompt' => $generation->prompt,
            'style_prompt' => $generation->style_prompt,
            'lyrics' => $generation->lyrics,
            'instrumental' => (bool) $generation->instrumental,
            'language_code' => $generation->language_code,

            // UNKNOWN by default and never inferred from a successful
            // generation. Shown on its own, not folded in beside the status,
            // because "the audio arrived" and "the audio may be sold" are
            // different questions and only one of them has been answered.
            'commercial_rights_status' => $generation->commercial_rights_status->value,

            'project' => $generation->project === null ? null : [
                'uuid' => $generation->project->uuid,
                'name' => $generation->project->name,
            ],

            // A sentence the provider wrote about its own failure. Kept: it is
            // the only thing that distinguishes "your prompt was refused" from
            // "the provider was down", and unlike a storage driver's message
            // it names nothing about this server.
            'failure_reason' => ProviderFailure::forDisplay($generation->failure_reason),

            'poll_count' => $generation->poll_count,
            'submitted_at' => $generation->submitted_at?->toAtomString(),
            'completed_at' => $generation->completed_at?->toAtomString(),
            'last_polled_at' => $generation->last_polled_at?->toAtomString(),
            'created_at' => $generation->created_at?->toAtomString(),

            'results' => $this->results($generation),
            'actions' => $this->actions($generation, $viewer),
        ];
    }

    /**
     * Which actions this generation is in a state to receive, and whether this
     * person may take them.
     *
     * **Presentation, never authorisation.** `can.role:catalogue` on the route
     * decides who may act and the services decide what is permissible; this
     * exists so a button can be disabled with an explanation instead of
     * failing when pressed. The predicates are the enum's own.
     *
     * @return array<string, mixed>
     */
    private function actions(MusicGeneration $generation, User $viewer): array
    {
        $mayWrite = $viewer->role->canWriteCatalogue();

        return [
            'may_act' => $mayWrite,
            'can_cancel' => $mayWrite && $generation->status->isCancellable(),
            // Results can only be taken into the review queue once the
            // provider has finished; selecting mid-flight would fetch audio
            // that is not final.
            'can_select' => $mayWrite && $generation->status === MusicGenerationStatus::Completed,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function results(MusicGeneration $generation): array
    {
        $rows = [];

        /** @var MusicGenerationResult $result */
        foreach ($generation->results as $result) {
            $rows[] = [
                'uuid' => $result->uuid,
                'title' => $result->title,
                'duration_ms' => $result->duration_ms,
                'selected' => (bool) $result->selected,

                // Whether there is anything to import — not where it is.
                'has_audio' => $result->hasAudio(),

                // Where it went, once it went somewhere. A selected result
                // becomes a verified asset and a candidate; it never becomes a
                // Track directly, which is why the link is to the review queue
                // and not to the catalogue.
                'asset_uuid' => $result->asset?->uuid,
                'candidate_uuid' => $result->candidate?->uuid,
            ];
        }

        return $rows;
    }
}
