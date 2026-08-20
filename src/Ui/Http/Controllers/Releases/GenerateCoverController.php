<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Releases;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use SaniTube\Artwork\Exceptions\ArtworkGenerationException;
use SaniTube\Artwork\Jobs\GenerateArtworkJob;
use SaniTube\Artwork\Services\RequestArtworkGeneration;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Services\RecordAuditEvent;
use SaniTube\Releases\Models\Release;
use SaniTube\Ui\Http\Requests\Releases\GenerateCoverRequest;

/**
 * Asking for a cover.
 *
 * **WHO CALLS THIS IN PRODUCTION: the release builder's artwork section.**
 * ART-003 measured whether generation was possible here and said so; it
 * deliberately stopped short of a button because a paid call had no ceiling.
 * ART-004 built the ceiling, so the button can exist.
 *
 * **Nothing is generated inside this request.** The record is written, the work
 * is queued, and a person gets an answer immediately. A provider call inside an
 * HTTP request is one that half-runs whenever somebody navigates away — and
 * this one costs money when it does.
 *
 * **Refused synchronously where the answer is already known.** A ceiling
 * reached, a paused installation, an open circuit or requirements the provider
 * cannot meet are all knowable now, and telling somebody now — in their own
 * language, by code — is better than a row that fails quietly in a queue.
 *
 * The prompt is never audited. It is catalogue data, it is already on the
 * generation row where it can be edited, and an audit table is not the place
 * for free text somebody will want to change.
 */
final class GenerateCoverController
{
    public function __invoke(
        GenerateCoverRequest $request,
        Release $release,
        RequestArtworkGeneration $requests,
        RecordAuditEvent $audit,
    ): RedirectResponse {
        /** @var User $operator */
        $operator = $request->user();

        try {
            $generation = $requests->handle(
                prompt: $request->prompt(),
                release: $release,
                quality: $request->quality(),
                requestedBy: $operator->getKey(),
            );
        } catch (ArtworkGenerationException $exception) {
            $audit->refused(AuditAction::ArtworkGenerationRequested, $exception->refusal->value, $release->uuid);

            return back()->withErrors(['artwork' => $exception->refusal->value]);
        }

        GenerateArtworkJob::dispatch($generation->uuid);

        $audit->record(
            AuditAction::ArtworkGenerationRequested,
            subjectUuid: $generation->uuid,
            context: ['release' => $release->uuid, 'provider' => $generation->provider],
        );

        return back()->with('status', 'releases.cover_generation_queued');
    }
}
