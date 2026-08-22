<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Editorial;

use Illuminate\Http\RedirectResponse;
use SaniTube\Artists\Models\Artist;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Services\RecordAuditEvent;
use SaniTube\Editorial\Exceptions\EditorialProfileException;
use SaniTube\Editorial\Models\EditorialProfile;
use SaniTube\Editorial\Services\WriteEditorialProfile;
use SaniTube\Ui\Http\Requests\Editorial\WriteProfileRequest;

/**
 * Making and correcting an imprint's editorial policy.
 *
 * PROD-002. `WriteEditorialProfile` had no caller anywhere in the product:
 * no controller, no console command, no seeder. A profile came into being
 * through a database client or not at all — and a production plan requires
 * one, so the planner could not be started from inside the product either.
 *
 * **Retiring, never deleting.** A profile is referenced by every plan pointed
 * at it and the foreign key is `restrictOnDelete`, so a delete would work only
 * for profiles nothing has ever used and fail for exactly the ones somebody
 * wants gone. `is_active` is the operation, and the writer refuses to point a
 * plan at a retired one.
 *
 * Audited, because a profile is what every unattended generation is written
 * against: changing one silently changes everything the platform writes from
 * then on, for every plan pointed at it.
 */
final class ProfileActionController
{
    public function store(
        WriteProfileRequest $request,
        WriteEditorialProfile $profiles,
        RecordAuditEvent $audit,
    ): RedirectResponse {
        $attributes = $this->withArtist($request, []);

        if ($attributes === null) {
            return back()->withErrors(['editorial' => 'EDITORIAL_ARTIST_UNKNOWN']);
        }

        try {
            $profile = $profiles->create($attributes);
        } catch (EditorialProfileException $exception) {
            $audit->refused(AuditAction::EditorialProfileCreated, $exception->reason);

            return back()->withErrors(['editorial' => $exception->reason]);
        }

        $audit->record(AuditAction::EditorialProfileCreated, subjectUuid: $profile->uuid);

        return back()->with('status', 'editorial.profile_created');
    }

    public function update(
        WriteProfileRequest $request,
        EditorialProfile $profile,
        WriteEditorialProfile $profiles,
        RecordAuditEvent $audit,
    ): RedirectResponse {
        $attributes = $this->withArtist($request, []);

        if ($attributes === null) {
            return back()->withErrors(['editorial' => 'EDITORIAL_ARTIST_UNKNOWN']);
        }

        try {
            $profiles->update($profile, $attributes);
        } catch (EditorialProfileException $exception) {
            $audit->refused(AuditAction::EditorialProfileUpdated, $exception->reason, $profile->uuid);

            return back()->withErrors(['editorial' => $exception->reason]);
        }

        // Which fields, never their contents. The guidance an imprint gives is
        // the label's own writing, and an audit log is not where a copy of it
        // accumulates.
        $audit->record(
            AuditAction::EditorialProfileUpdated,
            subjectUuid: $profile->uuid,
            // Joined rather than a list: the audit redaction drops numeric
            // keys, and a list inside a context arrives as an empty one — a
            // record that says a change happened and nothing about what.
            context: ['fields' => implode(',', array_keys($attributes))],
        );

        return back()->with('status', 'editorial.profile_updated');
    }

    /**
     * Resolve the credit this imprint usually releases under.
     *
     * The browser sends a uuid, never a row id: an id in a form is a number
     * somebody can increment, and the artist table is one where the next id is
     * always somebody.
     *
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>|null null when a named artist does not exist
     */
    private function withArtist(WriteProfileRequest $request, array $base): ?array
    {
        $attributes = [...$base, ...$request->profileAttributes()];

        if (! $request->mentionsDefaultArtist()) {
            return $attributes;
        }

        $uuid = $request->defaultArtistUuid();

        if ($uuid === null) {
            // Mentioned and empty: the imprint no longer has a usual credit.
            $attributes['default_artist_id'] = null;

            return $attributes;
        }

        $artist = Artist::query()->where('uuid', $uuid)->first();

        if (! $artist instanceof Artist) {
            return null;
        }

        $attributes['default_artist_id'] = $artist->id;

        return $attributes;
    }
}
