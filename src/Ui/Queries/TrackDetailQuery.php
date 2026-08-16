<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use SaniTube\Assets\Models\Asset;
use SaniTube\Catalog\Enums\TrackStatus;
use SaniTube\Catalog\Models\ExternalIdentifier;
use SaniTube\Catalog\Models\Track;
use SaniTube\Catalog\Models\TrackArtist;
use SaniTube\Catalog\Models\TrackContributor;
use SaniTube\Foundation\Validation\ValidationIssue;
use SaniTube\Media\Models\AudioAnalysis;
use SaniTube\Releases\Models\ReleaseTrack;

/**
 * One track, assembled for the screen and stripped of everything the screen has
 * no business knowing.
 *
 * The stripping is the point. A track's master asset knows its disk, its path
 * and its original filename; the analysis row knows the absolute location it
 * was read from. None of that reaches the browser here — not because it is
 * secret in the cryptographic sense, but because a storage layout published in
 * page source is a storage layout that cannot be changed afterwards, and a
 * bucket name in a screenshot is a bucket name in a support thread.
 *
 * What the screen gets instead is what an operator actually needs: the shape of
 * the audio, whether it verified, and how to refer to it.
 */
final readonly class TrackDetailQuery
{
    /**
     * @return array<string, mixed>
     */
    public function forTrack(Track $track, ?User $viewer = null): array
    {
        // Loaded through the typed pivot models rather than the many-to-many
        // relations. `$artist->pivot->role` is an untyped property bag that
        // static analysis cannot check and a rename would silently break;
        // TrackArtist and ReleaseTrack are real models with real casts.
        $track->load([
            'composition',
            'masterAsset',
            // Active identifiers only. A revoked ISRC is a historical fact and
            // belongs to an audit view; presenting it here as the track's
            // identity is how a superseded code reaches a distributor.
            // Scoped at this boundary rather than globally on the model, so
            // reconciliation code can still ask for revoked rows on purpose.
            'externalIdentifiers' => fn (Relation $relation) => $relation
                ->where('active_marker', ExternalIdentifier::ACTIVE)
                ->orderByDesc('is_authoritative')
                ->orderByDesc('assigned_at'),
        ]);

        $problems = $track->readinessProblems();
        $mayWrite = $viewer?->role->canWriteCatalogue() ?? false;
        $creditable = ! in_array($track->status, [TrackStatus::Released, TrackStatus::Archived], true);

        $artistCredits = TrackArtist::query()->with('artist')->where('track_id', $track->getKey())->get();
        $contributorCredits = TrackContributor::query()->with('contributor')->where('track_id', $track->getKey())->get();
        $placements = ReleaseTrack::query()->with('release')->where('track_id', $track->getKey())->get();

        return [
            'uuid' => $track->uuid,
            'title' => $track->title,
            'version_title' => $track->version_title,
            'status' => $track->status->value,
            'source' => $track->source->value,
            'language_code' => $track->language_code,
            'is_instrumental' => $track->is_instrumental,
            'is_explicit' => $track->is_explicit,
            'duration_ms' => $track->duration_ms,
            'bpm' => $track->bpm,
            'musical_key' => $track->musical_key,
            'genre_primary' => $track->genre_primary,
            'genre_secondary' => $track->genre_secondary,
            'recording_year' => $track->recording_year,
            'p_line' => $track->p_line,
            'created_at' => $track->created_at?->toAtomString(),
            'updated_at' => $track->updated_at?->toAtomString(),

            'artists' => $artistCredits->map(fn (TrackArtist $credit): array => [
                'uuid' => (string) $credit->artist?->uuid,
                'name' => (string) $credit->artist?->name,
                'role' => $credit->role->value,
            ])->values()->all(),

            'contributors' => $contributorCredits->map(fn (TrackContributor $credit): array => [
                'uuid' => (string) $credit->contributor?->uuid,
                'name' => (string) ($credit->contributor === null
                    ? ''
                    : ($credit->contributor->display_name ?? $credit->contributor->legal_name)),
                'role' => $credit->role->value,
            ])->values()->all(),

            'composition' => $track->composition === null ? null : [
                'uuid' => $track->composition->uuid,
                'title' => $track->composition->title,
                'status' => $track->composition->status->value,
            ],

            'master' => $this->master($track->masterAsset),

            'identifiers' => $track->externalIdentifiers->map(fn ($identifier): array => [
                'uuid' => $identifier->uuid,
                'type' => $identifier->type->value,
                'namespace' => $identifier->namespace,
                'value' => $identifier->value,
                'is_authoritative' => $identifier->is_authoritative,
                'source' => $identifier->source->value,
                'assigned_at' => $identifier->assigned_at->toAtomString(),
            ])->values()->all(),

            'releases' => $placements->map(fn (ReleaseTrack $placement): array => [
                'uuid' => (string) $placement->release?->uuid,
                'title' => (string) $placement->release?->title,
                'status' => (string) $placement->release?->status->value,
                'disc_number' => $placement->disc_number,
                'track_number' => $placement->track_number,
                'is_focus_track' => (bool) $placement->is_focus_track,
            ])->values()->all(),

            // The same list markReady() throws on — not a second opinion about
            // readiness, the one the domain will act on. CAT-002 made these
            // issues rather than sentences so the screen can say them in
            // somebody's own language.
            'readiness_problems' => array_map(
                static fn (ValidationIssue $issue): array => $issue->toArray(),
                $problems,
            ),

            'actions' => [
                // Presentation, never authorisation. `can.role:catalogue` on
                // the route decides who may act; this exists so a button can
                // be absent with an explanation rather than failing on save.
                'may_edit' => $mayWrite,
                'can_edit_credits' => $mayWrite && $creditable,
                'can_mark_ready' => $mayWrite
                    && $track->status === TrackStatus::Draft
                    && $problems === [],
            ],
        ];
    }

    /**
     * The master asset, described without saying where it lives.
     *
     * `disk`, `path` and `original_filename` are deliberately absent. The
     * checksum is abbreviated: the first twelve characters are enough for a
     * human to compare two rows, and the full digest belongs in an export, not
     * on a screen where it is only ever going to be eyeballed.
     *
     * @return array<string, mixed>|null
     */
    private function master(?Asset $asset): ?array
    {
        if ($asset === null) {
            return null;
        }

        // Queried rather than eager-loaded: Asset has no analyses relation, and
        // adding one to a domain model to serve a screen is the wrong direction
        // for that dependency. One extra query on a detail page for one track.
        $analysis = AudioAnalysis::query()
            ->where('asset_id', $asset->getKey())
            ->orderByDesc('analyzed_at')
            ->first();

        return [
            'uuid' => $asset->uuid,
            'kind' => $asset->kind->value,
            'status' => $asset->status->value,
            'mime_type' => $asset->mime_type,
            'byte_size' => $asset->byte_size,
            'checksum_short' => substr((string) $asset->sha256, 0, 12),
            'duration_ms' => $asset->duration_ms,
            'sample_rate' => $asset->sample_rate,
            'bit_depth' => $asset->bit_depth,
            'channels' => $asset->channels,
            'stored_at' => $asset->stored_at?->toAtomString(),
            'verified_at' => $asset->verified_at?->toAtomString(),
            'is_duplicate' => $asset->duplicate_of_asset_id !== null,
            'analysis' => $analysis === null ? null : [
                'succeeded' => (bool) $analysis->succeeded,
                'analyzer' => $analysis->analyzer,
                'codec' => $analysis->codec,
                'container' => $analysis->container,
                'sample_rate' => $analysis->sample_rate,
                'bit_depth' => $analysis->bit_depth,
                'channels' => $analysis->channels,
                'bitrate' => $analysis->bitrate,
                'loudness_lufs' => $analysis->loudness_lufs === null ? null : (float) $analysis->loudness_lufs,
                'peak_dbfs' => $analysis->peak_dbfs === null ? null : (float) $analysis->peak_dbfs,
                'analyzed_at' => $analysis->analyzed_at?->toAtomString(),
                // `failure_message` is not exposed: it quotes whatever the
                // analyser said, which on a failed read is a filesystem path.
                'failed' => ! $analysis->succeeded,
            ],
        ];
    }
}
