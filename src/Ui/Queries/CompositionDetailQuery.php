<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use Illuminate\Database\Eloquent\Relations\Relation;
use SaniTube\Catalog\Models\Composition;
use SaniTube\Catalog\Models\CompositionContributor;
use SaniTube\Catalog\Models\ExternalIdentifier;
use SaniTube\Catalog\Models\Track;

/**
 * One composition — the work, as distinct from the recordings of it.
 *
 * The distinction this screen must not blur: `composition_contributor.share`
 * is a **catalogue credit**, recorded so the catalogue can say who wrote what.
 * It is **not** an ownership split and **not** a royalty entitlement. Those
 * belong to a rights domain that does not exist yet, and presenting a credit
 * share as an entitlement would be inventing a financial fact.
 *
 * The field is therefore named `credited_share` everywhere it travels, and the
 * screen labels it as a credit. When RIGHTS-001 lands it will introduce its own
 * model; nothing here is to be reused as ownership.
 */
final readonly class CompositionDetailQuery
{
    private const RECORDING_LIMIT = 50;

    /**
     * @return array<string, mixed>
     */
    public function forComposition(Composition $composition): array
    {
        $composition->load([
            'externalIdentifiers' => fn (Relation $relation) => $relation
                ->where('active_marker', ExternalIdentifier::ACTIVE)
                ->orderByDesc('is_authoritative')
                ->orderByDesc('assigned_at'),
        ]);

        $composition->loadCount(['tracks', 'contributors']);

        $credits = CompositionContributor::query()
            ->with('contributor')
            ->join('contributors', 'contributors.id', '=', 'composition_contributor.contributor_id')
            ->whereRaw('composition_contributor.composition_id = ?', [$composition->getKey()])
            ->orderBy('composition_contributor.position')
            ->orderBy('contributors.legal_name')
            ->orderBy('contributors.uuid')
            ->limit(self::RECORDING_LIMIT)
            ->select('composition_contributor.*')
            ->get();

        $recordings = Track::query()
            ->where('composition_id', $composition->getKey())
            ->orderBy('title')
            ->orderBy('uuid')
            ->limit(self::RECORDING_LIMIT)
            ->get();

        return [
            'uuid' => $composition->uuid,
            'title' => $composition->title,
            'alternative_title' => $composition->alternative_title,
            'language_code' => $composition->language_code,
            'created_year' => $composition->created_year,
            'is_public_domain' => $composition->is_public_domain,
            'status' => $composition->status->value,
            'track_count' => (int) $composition->tracks_count,
            'contributor_count' => (int) $composition->contributors_count,
            'created_at' => $composition->created_at?->toAtomString(),
            'updated_at' => $composition->updated_at?->toAtomString(),

            'identifiers' => $composition->externalIdentifiers->map(fn (ExternalIdentifier $identifier): array => [
                'uuid' => $identifier->uuid,
                'type' => $identifier->type->value,
                'namespace' => $identifier->namespace,
                'value' => $identifier->value,
                'is_authoritative' => $identifier->is_authoritative,
                'source' => $identifier->source->value,
                'assigned_at' => $identifier->assigned_at->toAtomString(),
            ])->values()->all(),

            'contributors' => $credits->map(fn (CompositionContributor $credit): array => [
                'uuid' => (string) $credit->contributor?->uuid,
                'name' => (string) ($credit->contributor === null
                    ? ''
                    : ($credit->contributor->display_name ?? $credit->contributor->legal_name)),
                'role' => $credit->role->value,
                // Credit, not ownership. See the class docblock.
                'credited_share' => $credit->share === null ? null : (float) $credit->share,
            ])->values()->all(),

            'recordings' => $recordings->map(fn (Track $track): array => [
                'uuid' => $track->uuid,
                'title' => $track->title,
                'version_title' => $track->version_title,
                'status' => $track->status->value,
            ])->values()->all(),

            'contributors_shown' => $credits->count(),
            'contributors_truncated' => (int) $composition->contributors_count > self::RECORDING_LIMIT,
            'recordings_shown' => $recordings->count(),
            'recordings_truncated' => (int) $composition->tracks_count > self::RECORDING_LIMIT,
        ];
    }
}
