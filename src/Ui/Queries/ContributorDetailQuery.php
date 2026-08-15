<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use Illuminate\Database\Eloquent\Relations\Relation;
use SaniTube\Catalog\Models\CompositionContributor;
use SaniTube\Catalog\Models\ExternalIdentifier;
use SaniTube\Catalog\Models\TrackContributor;
use SaniTube\Contributors\Models\Contributor;

/**
 * One contributor, with what they are credited on.
 *
 * Two rules carried from the track and artist screens. **Active identifiers
 * only** — a revoked IPI or ISNI is a historical fact and belongs to an audit
 * view, not to the person's current identity — scoped here rather than as a
 * global scope so reconciliation code can still ask for revoked rows. And **no
 * internal key** in anything the browser receives.
 *
 * One thing is specific to contributors: `legal_name` and `display_name` are
 * different facts about a person, and the legal name is the more sensitive of
 * the two. It is exposed here because the catalogue's whole purpose is
 * establishing who is credited on what, and a rights conversation that cannot
 * name the party is useless. It is *labelled* as the legal name rather than
 * merged into one "name" field, so nobody publishes it by accident thinking it
 * was a stage name.
 */
final readonly class ContributorDetailQuery
{
    /** A cap, not a page: the full filtered list is what the track screen is for. */
    private const CREDIT_LIMIT = 50;

    /**
     * @return array<string, mixed>
     */
    public function forContributor(Contributor $contributor): array
    {
        $contributor->load([
            'externalIdentifiers' => fn (Relation $relation) => $relation
                ->where('active_marker', ExternalIdentifier::ACTIVE)
                ->orderByDesc('is_authoritative')
                ->orderByDesc('assigned_at'),
        ]);

        $contributor->loadCount(['compositions', 'tracks']);

        // Ordered before capped, and by a field a reader can reason about. A
        // bare LIMIT returns whatever the engine finds convenient, which means
        // two loads can disagree — and the four engines this platform runs on
        // will disagree with each other as well.
        $trackCredits = TrackContributor::query()
            ->with('track')
            ->join('tracks', 'tracks.id', '=', 'track_contributor.track_id')
            ->whereRaw('track_contributor.contributor_id = ?', [$contributor->getKey()])
            ->orderBy('tracks.title')
            ->orderBy('tracks.uuid')
            ->limit(self::CREDIT_LIMIT)
            ->select('track_contributor.*')
            ->get();

        $compositionCredits = CompositionContributor::query()
            ->with('composition')
            ->join('compositions', 'compositions.id', '=', 'composition_contributor.composition_id')
            ->whereRaw('composition_contributor.contributor_id = ?', [$contributor->getKey()])
            ->orderBy('compositions.title')
            ->orderBy('compositions.uuid')
            ->limit(self::CREDIT_LIMIT)
            ->select('composition_contributor.*')
            ->get();

        return [
            'uuid' => $contributor->uuid,
            'name' => $contributor->display_name ?? $contributor->legal_name,
            'legal_name' => $contributor->legal_name,
            'display_name' => $contributor->display_name,
            'type' => $contributor->type->value,
            'country' => $contributor->country,
            'notes' => $contributor->notes,
            'composition_count' => (int) $contributor->compositions_count,
            'track_count' => (int) $contributor->tracks_count,
            'created_at' => $contributor->created_at?->toAtomString(),
            'updated_at' => $contributor->updated_at?->toAtomString(),

            'identifiers' => $contributor->externalIdentifiers->map(fn (ExternalIdentifier $identifier): array => [
                'uuid' => $identifier->uuid,
                'type' => $identifier->type->value,
                'namespace' => $identifier->namespace,
                'value' => $identifier->value,
                'is_authoritative' => $identifier->is_authoritative,
                'source' => $identifier->source->value,
                'assigned_at' => $identifier->assigned_at->toAtomString(),
            ])->values()->all(),

            'tracks' => $trackCredits->map(fn (TrackContributor $credit): array => [
                'uuid' => (string) $credit->track?->uuid,
                'title' => (string) $credit->track?->title,
                'status' => (string) $credit->track?->status->value,
                'role' => $credit->role->value,
            ])->values()->all(),

            'compositions' => $compositionCredits->map(fn (CompositionContributor $credit): array => [
                'uuid' => (string) $credit->composition?->uuid,
                'title' => (string) $credit->composition?->title,
                'status' => (string) $credit->composition?->status->value,
                'role' => $credit->role->value,
                // A catalogue credit share, NOT an ownership split. The rights
                // domain is separate and does not exist yet; presenting this
                // number as a royalty entitlement would be inventing one.
                'credited_share' => $credit->share === null ? null : (float) $credit->share,
            ])->values()->all(),

            // Actual counts, never the cap — reporting the cap made an artist
            // with one credit claim fifty were shown.
            'tracks_shown' => $trackCredits->count(),
            'tracks_truncated' => (int) $contributor->tracks_count > self::CREDIT_LIMIT,
            'compositions_shown' => $compositionCredits->count(),
            'compositions_truncated' => (int) $contributor->compositions_count > self::CREDIT_LIMIT,
        ];
    }
}
