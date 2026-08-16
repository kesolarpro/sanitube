<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Releases;

use Illuminate\Http\RedirectResponse;
use SaniTube\Artists\Models\Artist;
use SaniTube\Assets\Models\Asset;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Services\RecordAuditEvent;
use SaniTube\Catalog\Models\Track;
use SaniTube\Releases\Enums\ReleaseArtistRole;
use SaniTube\Releases\Enums\ReleaseType;
use SaniTube\Releases\Exceptions\ReleaseBuilderException;
use SaniTube\Releases\Exceptions\ReleaseNotReadyException;
use SaniTube\Releases\Models\Release;
use SaniTube\Releases\Services\ReleaseBuilder;
use SaniTube\Ui\Http\Requests\Releases\CreateReleaseRequest;
use SaniTube\Ui\Http\Requests\Releases\ReleaseArtistsRequest;
use SaniTube\Ui\Http\Requests\Releases\ReleaseCoverRequest;
use SaniTube\Ui\Http\Requests\Releases\ReleaseDetailsRequest;
use SaniTube\Ui\Http\Requests\Releases\ReleaseTrackOrderRequest;
use SaniTube\Ui\Http\Requests\Releases\ReleaseTrackRequest;

/**
 * Every change a person can make to a release.
 *
 * All of them go through {@see ReleaseBuilder}, and **none of them writes
 * `status`**. Readiness is *earned* by passing validation, not assigned:
 * `markReady()` re-runs the invariants and throws if they do not hold, so a
 * controller that set `status = READY` would be skipping I4 and I9 while
 * looking like it did the same thing.
 *
 * `ReleaseMutationPolicy` is likewise never reimplemented here. Whether a
 * SUBMITTED release still accepts an edit is a property of the status, the
 * builder asks that question on every mutation, and a second copy of the
 * answer in a controller is the copy that eventually disagrees.
 *
 * Refusals come back as machine-readable codes through `withErrors`. Both
 * exceptions carry one; the interface translates it. The domain's English is
 * written for a developer reading a log.
 */
final class ReleaseActionController
{
    public function store(
        CreateReleaseRequest $request,
        ReleaseBuilder $builder,
        RecordAuditEvent $audit,
    ): RedirectResponse {
        $release = $builder->createDraft(
            title: $request->title(),
            type: ReleaseType::from($request->type()),
            languageCode: $request->languageCode(),
            versionTitle: $request->versionTitle(),
        );

        $audit->record(AuditAction::ReleaseCreated, subjectUuid: $release->uuid);

        return redirect()
            ->to('/releases/'.$release->uuid)
            ->with('status', 'release.created');
    }

    public function updateDetails(
        ReleaseDetailsRequest $request,
        Release $release,
        ReleaseBuilder $builder,
    ): RedirectResponse {
        try {
            $builder->updateDetails($release, $request->details());
        } catch (ReleaseBuilderException $exception) {
            return $this->refused($exception->reason);
        }

        return back()->with('status', 'release.saved');
    }

    public function addTrack(
        ReleaseTrackRequest $request,
        Release $release,
        ReleaseBuilder $builder,
    ): RedirectResponse {
        $track = Track::query()->where('uuid', $request->trackUuid())->first();

        if (! $track instanceof Track) {
            return $this->refused('UNKNOWN_TRACK');
        }

        try {
            $builder->addTrack($release, $track, $request->disc(), $request->isFocusTrack());
        } catch (ReleaseBuilderException $exception) {
            return $this->refused($exception->reason);
        }

        return back()->with('status', 'release.track_added');
    }

    public function removeTrack(
        ReleaseTrackRequest $request,
        Release $release,
        ReleaseBuilder $builder,
    ): RedirectResponse {
        $track = Track::query()->where('uuid', $request->trackUuid())->first();

        if (! $track instanceof Track) {
            return $this->refused('UNKNOWN_TRACK');
        }

        try {
            // Removing closes the gap it leaves. A tracklist running 1, 2, 4
            // is rejected by stores, and the builder renumbers rather than
            // leaving the release invalid until somebody notices.
            $builder->removeTrack($release, $track);
        } catch (ReleaseBuilderException $exception) {
            return $this->refused($exception->reason);
        }

        return back()->with('status', 'release.track_removed');
    }

    public function reorder(
        ReleaseTrackOrderRequest $request,
        Release $release,
        ReleaseBuilder $builder,
    ): RedirectResponse {
        try {
            // Two-phase inside the builder: every row is parked above the
            // occupied range before final numbers are written, because
            // UNIQUE(release_id, disc_number, track_number) collides the moment
            // a track moves into a slot another has not yet left.
            $builder->reorder($release, $request->trackUuids(), $request->disc());
        } catch (ReleaseBuilderException $exception) {
            return $this->refused($exception->reason);
        }

        return back()->with('status', 'release.reordered');
    }

    public function setArtists(
        ReleaseArtistsRequest $request,
        Release $release,
        ReleaseBuilder $builder,
    ): RedirectResponse {
        $credits = [];

        foreach ($request->credits() as $credit) {
            $artist = Artist::query()->where('uuid', $credit['uuid'])->first();

            if (! $artist instanceof Artist) {
                return $this->refused('UNKNOWN_ARTIST');
            }

            $credits[] = ['artist' => $artist, 'role' => ReleaseArtistRole::from($credit['role'])];
        }

        try {
            // "At least one PRIMARY" is enforced by the builder, not restated
            // here: it is a property of the credit list, not of the form.
            $builder->setArtists($release, $credits);
        } catch (ReleaseBuilderException $exception) {
            return $this->refused($exception->reason);
        }

        return back()->with('status', 'release.artists_saved');
    }

    public function setCover(
        ReleaseCoverRequest $request,
        Release $release,
        ReleaseBuilder $builder,
    ): RedirectResponse {
        $cover = Asset::query()->where('uuid', $request->assetUuid())->first();

        if (! $cover instanceof Asset) {
            return $this->refused('UNKNOWN_ASSET');
        }

        try {
            // The builder refuses a non-ARTWORK or unverified asset. Catching
            // it here rather than at readiness means the mistake is reported
            // when it is made, not when somebody is trying to deliver.
            $builder->setCover($release, $cover);
        } catch (ReleaseBuilderException $exception) {
            return $this->refused($exception->reason);
        }

        return back()->with('status', 'release.cover_saved');
    }

    /**
     * Mark ready — earned, never assigned.
     */
    public function markReady(Release $release, RecordAuditEvent $audit): RedirectResponse
    {
        try {
            $release->markReady();
        } catch (ReleaseNotReadyException) {
            // The problems are already in the payload the screen renders, from
            // the same `readinessProblems()` this throws on. Repeating them in
            // a flash message would be a second copy that can disagree.
            $audit->refused(AuditAction::ReleaseMarkedReady, 'RELEASE_NOT_READY', $release->uuid);

            return $this->refused('RELEASE_NOT_READY');
        }

        $audit->record(AuditAction::ReleaseMarkedReady, subjectUuid: $release->uuid);

        return back()->with('status', 'release.ready');
    }

    public function reopen(Release $release, ReleaseBuilder $builder, RecordAuditEvent $audit): RedirectResponse
    {
        try {
            $builder->reopen($release);
        } catch (ReleaseBuilderException $exception) {
            $audit->refused(AuditAction::ReleaseReopened, $exception->reason, $release->uuid);

            return $this->refused($exception->reason);
        }

        $audit->record(AuditAction::ReleaseReopened, subjectUuid: $release->uuid);

        return back()->with('status', 'release.reopened');
    }

    private function refused(string $code): RedirectResponse
    {
        return back()->withErrors(['release' => $code]);
    }
}
