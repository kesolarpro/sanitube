<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use Illuminate\Database\Eloquent\Relations\Relation;
use SaniTube\Artists\Models\Artist;
use SaniTube\Catalog\Models\ExternalIdentifier;
use SaniTube\Catalog\Models\TrackArtist;

/**
 * One artist, with what they are actually on.
 *
 * The two rules established by the track screens hold here too: **active
 * identifiers only** — a revoked ISNI or IPI is a historical fact and belongs
 * to an audit view, not to the artist's current identity — and **no internal
 * key** in anything the browser receives.
 *
 * The scoping is at this boundary rather than as a global scope on
 * ExternalIdentifier, so reconciliation code can still ask for revoked rows.
 */
final readonly class ArtistDetailQuery
{
    /** Enough to show the body of work without turning a page into an export. */
    private const TRACK_LIMIT = 50;

    /**
     * @return array<string, mixed>
     */
    public function forArtist(Artist $artist): array
    {
        $artist->load([
            'externalIdentifiers' => fn (Relation $relation) => $relation
                ->where('active_marker', ExternalIdentifier::ACTIVE)
                ->orderByDesc('is_authoritative')
                ->orderByDesc('assigned_at'),
        ]);

        $artist->loadCount(['tracks', 'releases']);

        // Through the typed pivot rather than `$track->pivot`, which static
        // analysis cannot check and a column rename would silently break.
        $credits = TrackArtist::query()
            ->with('track')
            ->where('artist_id', $artist->getKey())
            ->limit(self::TRACK_LIMIT)
            ->get();

        return [
            'uuid' => $artist->uuid,
            'name' => $artist->name,
            'sort_name' => $artist->sort_name,
            'slug' => $artist->slug,
            'type' => $artist->type->value,
            'status' => $artist->status->value,
            'country' => $artist->country,
            'primary_locale' => $artist->primary_locale,
            'biography' => $artist->biography,
            'is_owner_controlled' => $artist->is_owner_controlled,
            'track_count' => (int) $artist->tracks_count,
            'release_count' => (int) $artist->releases_count,
            'created_at' => $artist->created_at?->toAtomString(),
            'updated_at' => $artist->updated_at?->toAtomString(),

            'identifiers' => $artist->externalIdentifiers->map(fn (ExternalIdentifier $identifier): array => [
                'uuid' => $identifier->uuid,
                'type' => $identifier->type->value,
                'namespace' => $identifier->namespace,
                'value' => $identifier->value,
                'is_authoritative' => $identifier->is_authoritative,
                'source' => $identifier->source->value,
                'assigned_at' => $identifier->assigned_at->toAtomString(),
            ])->values()->all(),

            'tracks' => $credits->map(fn (TrackArtist $credit): array => [
                'uuid' => (string) $credit->track?->uuid,
                'title' => (string) $credit->track?->title,
                'status' => (string) $credit->track?->status->value,
                'role' => $credit->role->value,
            ])->values()->all(),

            // Stated rather than left to be inferred from a short list: a page
            // showing fifty of nine hundred tracks with no note reads as an
            // artist with fifty tracks.
            'tracks_truncated' => (int) $artist->tracks_count > self::TRACK_LIMIT,
            'tracks_shown' => self::TRACK_LIMIT,
        ];
    }
}
