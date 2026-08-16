<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Studio;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use SaniTube\MusicGeneration\Exceptions\GenerationException;
use SaniTube\MusicGeneration\Exceptions\UnknownGenerationProvider;
use SaniTube\MusicGeneration\Jobs\SubmitMusicGenerationJob;
use SaniTube\MusicGeneration\Models\GenerationProject;
use SaniTube\MusicGeneration\Models\MusicGeneration;
use SaniTube\MusicGeneration\Models\MusicGenerationResult;
use SaniTube\MusicGeneration\Services\CancelMusicGeneration;
use SaniTube\MusicGeneration\Services\CreateGenerationProject;
use SaniTube\MusicGeneration\Services\SelectMusicGenerationResult;
use SaniTube\MusicGeneration\Services\StartMusicGeneration;
use SaniTube\Ui\Http\Requests\Studio\CreateProjectRequest;
use SaniTube\Ui\Http\Requests\Studio\StartGenerationRequest;

/**
 * The four things a person can make the studio do.
 *
 * Every one calls a GEN-001 service. Nothing here writes a status, fetches
 * audio, or decides what a generation is worth — and in particular **nothing
 * here creates a Track**. A selected result becomes a verified asset and a
 * TrackCandidate with source GENERATED, which joins the same review queue as
 * an imported file. Generated audio is exactly the material that most needs a
 * person to listen before it enters the catalogue: it was produced by a machine
 * from a sentence.
 *
 * Starting a generation is the one action in the interface that **spends the
 * operator's money at a supplier**, which is why it queues the submission
 * rather than making the request inline: a browser that gives up mid-request
 * must not leave a paid job nobody has a record of. The row exists before the
 * provider is called, so a retry finds it.
 */
final class StudioActionController
{
    public function createProject(CreateProjectRequest $request, CreateGenerationProject $creator): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $project = $creator->handle(
            name: (string) $request->input('name'),
            targetTrackCount: $request->integer('target_track_count') ?: null,
            defaultLanguage: $request->input('default_language'),
            defaultGenre: $request->input('default_genre'),
            defaultStylePrompt: $request->input('default_style_prompt'),
            createdBy: $actor->getKey(),
        );

        return redirect()
            ->to('/studio/projects/'.$project->uuid)
            ->with('status', 'studio.project_created');
    }

    /**
     * Ask a supplier to make something.
     */
    public function startGeneration(StartGenerationRequest $request, StartMusicGeneration $starter): RedirectResponse
    {
        $project = $this->project($request->projectUuid());

        if ($request->projectUuid() !== null && ! $project instanceof GenerationProject) {
            return back()->withErrors(['generation' => 'UNKNOWN_PROJECT']);
        }

        try {
            $generation = $starter->handle(
                prompt: $request->prompt(),
                project: $project,
                stylePrompt: $request->stylePrompt(),
                lyrics: $request->lyrics(),
                instrumental: $request->instrumental(),
                languageCode: $request->languageCode(),
            );
        } catch (GenerationException $exception) {
            return back()->withErrors(['generation' => $exception->reason]);
        } catch (UnknownGenerationProvider) {
            // Named in configuration and unresolvable. The studio overview says
            // so plainly; this is the same fact reaching a form.
            return back()->withErrors(['generation' => 'UNKNOWN_PROVIDER']);
        }

        // Queued, not called. The audio takes minutes and the request must not.
        SubmitMusicGenerationJob::dispatch($generation->uuid);

        return redirect()
            ->to('/studio/generations/'.$generation->uuid)
            ->with('status', 'studio.generation_started');
    }

    public function cancelGeneration(MusicGeneration $generation, CancelMusicGeneration $canceller): RedirectResponse
    {
        try {
            $canceller->handle($generation);
        } catch (GenerationException $exception) {
            return back()->withErrors(['generation' => $exception->reason]);
        }

        return back()->with('status', 'studio.generation_cancelled');
    }

    /**
     * Take one result into the review queue.
     *
     * Never into the catalogue. The service fetches the audio, registers a
     * verified asset and creates a candidate; promoting it is a separate
     * decision taken on a separate screen, by a person who has listened.
     */
    public function selectResult(
        MusicGenerationResult $result,
        SelectMusicGenerationResult $selector,
    ): RedirectResponse {
        try {
            $candidate = $selector->handle($result);
        } catch (GenerationException $exception) {
            return back()->withErrors(['generation' => $exception->reason]);
        }

        return redirect()
            ->to('/ingestion/candidates/'.$candidate->uuid)
            ->with('status', 'studio.result_selected');
    }

    private function project(?string $uuid): ?GenerationProject
    {
        if ($uuid === null) {
            return null;
        }

        return GenerationProject::query()->where('uuid', $uuid)->first();
    }
}
