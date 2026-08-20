<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use App\Models\User;
use SaniTube\Artwork\Services\ArtworkGenerationReadiness;
use SaniTube\Artwork\Services\MeasuredDimensions;
use SaniTube\Catalog\Enums\ExternalIdentifierType;
use SaniTube\Catalog\ManuallyAssignableIdentifiers;
use SaniTube\Catalog\Models\ExternalIdentifier;
use SaniTube\Foundation\Validation\ValidationIssue;
use SaniTube\Releases\Enums\ReleaseArtistRole;
use SaniTube\Releases\Enums\ReleaseStatus;
use SaniTube\Releases\Models\Release;
use SaniTube\Releases\Models\ReleaseArtist;
use SaniTube\Releases\Models\ReleaseTrack;
use SaniTube\Releases\ReleaseMutationPolicy;
use SaniTube\Releases\Services\ValidateRelease;

/**
 * Everything the release builder needs, in one payload.
 *
 * Three things are **asked of the domain rather than decided here**, and that
 * is the whole design of this class:
 *
 *   - **What may be edited** comes from {@see ReleaseMutationPolicy}. A screen
 *     that decided for itself which fields a SUBMITTED release still accepts
 *     would be a second copy of I4, and the copy in the browser is the one
 *     that gets it wrong.
 *   - **What is wrong** comes from {@see ValidateRelease}. Errors and warnings
 *     are kept apart because a release with no cover cannot be delivered while
 *     one with no catalogue number is merely incomplete.
 *   - **Whether it can be marked ready** comes from
 *     `Release::readinessProblems()` — the same list `markReady()` throws on.
 *     The button is disabled by the same facts that would refuse the request.
 *
 * **Identifiers are recorded, never minted**, and CAT-005 did not change that.
 * A person may now type in a UPC they hold and it goes through
 * `AssignExternalIdentifier` like every other assignment — but nothing here
 * generates one. The distinction is the whole rule: a recording already
 * distributed elsewhere already has an identifier, and a platform that invented
 * a second one would split its earnings across two numbers, which surfaces
 * months later in a royalty report that no longer reconciles.
 */
final readonly class ReleaseDetailQuery
{
    public function __construct(
        private ValidateRelease $validator,
        private MeasuredDimensions $dimensions,
        private ArtworkGenerationReadiness $artwork,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forRelease(Release $release, User $viewer): array
    {
        $release->load(['coverAsset', 'artists', 'trackEntries']);

        $validation = $this->validator->handle($release);
        $mayWrite = $viewer->role->canWriteCatalogue();
        $structural = ReleaseMutationPolicy::allowsStructuralChange($release->status);

        return [
            'uuid' => $release->uuid,
            'title' => $release->title,
            'version_title' => $release->version_title,
            'type' => $release->type->value,
            'status' => $release->status->value,
            'label_name' => $release->label_name,
            'catalogue_number' => $release->catalogue_number,
            'release_date' => $release->release_date?->toDateString(),
            'original_release_date' => $release->original_release_date?->toDateString(),
            'language_code' => $release->language_code,
            'p_line' => $release->p_line,
            'c_line' => $release->c_line,
            'created_at' => $release->created_at?->toAtomString(),
            'updated_at' => $release->updated_at?->toAtomString(),

            'cover' => $this->cover($release),

            // ART-003. Whether this installation could generate a cover at all,
            // and which two numbers disagree when it could not. `ArtworkFeasibility`
            // has known this since ART-002 and had no caller, so an operator
            // who configured a provider and expected covers got nothing and no
            // explanation. Costs nothing to ask: it compares two settings.
            'artwork_generation' => $this->artwork->describe(),
            'artists' => $this->artists($release),
            'tracks' => $this->tracks($release),
            'identifiers' => $this->identifiers($release),

            // CAT-005. Which identifiers a *person* may type in here — a
            // narrower list than the registries allow, and asked of the
            // catalogue rather than restated in the browser. The screen builds
            // its select from this; the controller re-asks the same question
            // and refuses anything else.
            'assignable_identifier_types' => ManuallyAssignableIdentifiers::valuesFor($release),

            'validation' => $validation->toArray(),

            // The same list markReady() throws on. Not a second opinion about
            // readiness — the one the domain will act on. REL-002: issues with
            // codes, so the screen can say this in somebody's own language.
            'readiness_problems' => array_map(
                static fn (ValidationIssue $issue): array => $issue->toArray(),
                $release->readinessProblems(),
            ),

            'actions' => [
                'may_edit' => $mayWrite,

                // Presentation, never authorisation: the route middleware
                // decides who may act and ReleaseMutationPolicy decides what
                // a status permits. This exists so a field can be disabled
                // with an explanation rather than failing on save.
                'can_edit_details' => $mayWrite && $structural,
                'can_edit_tracks' => $mayWrite && $structural,
                'can_edit_artists' => $mayWrite && $structural,
                'can_edit_cover' => $mayWrite && $structural,

                'can_mark_ready' => $mayWrite
                    && $release->status === ReleaseStatus::Draft
                    && $release->readinessProblems() === [],

                'can_reopen' => $mayWrite && ReleaseMutationPolicy::allowsReopen($release->status),

                // Not gated on `$structural`. A UPC is routinely issued after a
                // release is submitted, and locking the field at that point
                // would leave the one identifier a store keys on unenterable
                // for exactly the releases that need it.
                'can_assign_identifier' => $mayWrite,
            ],
        ];
    }

    /**
     * The cover, described and never located.
     *
     * No path, no disk, no signed URL — the same rule the asset screens follow.
     * What the builder needs to know is that a cover exists and that it is
     * usable, and `verified` is the fact readiness turns on.
     *
     * @return array<string, mixed>|null
     */
    private function cover(Release $release): ?array
    {
        $cover = $release->coverAsset;

        if ($cover === null) {
            return null;
        }

        $dimensions = $this->dimensions->for($cover);

        return [
            'uuid' => $cover->uuid,
            'kind' => $cover->kind->value,
            'status' => $cover->status->value,
            // Measured, not declared. `assets.width` is a column nothing in
            // this platform writes; reading it showed an empty field beside a
            // verified checksum, which invites a reader to believe something
            // was checked.
            'width' => $dimensions['width'],
            'height' => $dimensions['height'],
            'byte_size' => $cover->byte_size,
            'verified' => $cover->isVerifiedArtwork(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function artists(Release $release): array
    {
        $rows = [];

        foreach ($release->artists as $artist) {
            // Read through the pivot model rather than the magic `pivot`
            // property. The pivot casts `role` to ReleaseArtistRole, and
            // treating it as a string worked right up until a release actually
            // had an artist on it — which is the only state this screen is
            // ever looked at in.
            $pivot = $artist->getRelation('pivot');

            $rows[] = [
                'uuid' => $artist->uuid,
                'name' => $artist->name,
                'role' => $pivot instanceof ReleaseArtist
                    ? $pivot->role->value
                    : ReleaseArtistRole::Primary->value,
                'position' => $pivot instanceof ReleaseArtist ? $pivot->position : 0,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return $rows;
    }

    /**
     * The tracklist, by disc, in the order it will be delivered in.
     *
     * Ordered by `(disc_number, track_number)` because that *is* the
     * tracklist. A builder that showed them in insertion order would be
     * showing something other than what a store receives.
     *
     * @return list<array<string, mixed>>
     */
    private function tracks(Release $release): array
    {
        $entries = $release->trackEntries()
            ->with('track')
            ->orderBy('disc_number')
            ->orderBy('track_number')
            ->get();

        $rows = [];

        /** @var ReleaseTrack $entry */
        foreach ($entries as $entry) {
            $track = $entry->track;

            if ($track === null) {
                continue;
            }

            $rows[] = [
                'uuid' => $track->uuid,
                'title' => $track->title,
                'version_title' => $track->version_title,
                'duration_ms' => $track->duration_ms,
                'is_explicit' => (bool) $track->is_explicit,
                'disc_number' => $entry->disc_number,
                'track_number' => $entry->track_number,
                'is_focus_track' => (bool) $entry->is_focus_track,
                'isrc' => $this->activeIdentifier($track, ExternalIdentifierType::Isrc),
            ];
        }

        return $rows;
    }

    /**
     * The release's own identifiers — read, never minted.
     *
     * Active only. A revoked UPC is history, and showing it beside a live one
     * would make a delivery look identified when it is not.
     *
     * @return list<array<string, mixed>>
     */
    private function identifiers(Release $release): array
    {
        $rows = [];

        $identifiers = ExternalIdentifier::query()
            ->active()
            ->where('identifiable_type', Release::class)
            ->where('identifiable_id', $release->getKey())
            ->orderBy('type')
            ->get();

        foreach ($identifiers as $identifier) {
            $rows[] = [
                // CAT-005 needs it to name one for revocation. A uuid rather
                // than the row id, which is a counter somebody can walk.
                'uuid' => $identifier->uuid,
                'type' => $identifier->type->value,
                'value' => $identifier->value,
                'source' => $identifier->source->value,
            ];
        }

        return $rows;
    }

    private function activeIdentifier(object $entity, ExternalIdentifierType $type): ?string
    {
        /** @var string|null $value */
        $value = ExternalIdentifier::query()
            ->active()
            ->where('identifiable_type', $entity::class)
            ->where('identifiable_id', $entity->getKey())
            ->where('type', $type->value)
            ->value('value');

        return $value;
    }
}
