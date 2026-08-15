<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Catalog;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Ui\Queries\TrackIndexQuery;

/**
 * The track list.
 *
 * The controller does no filtering of its own: it hands the request's query
 * string to the read model, which validates every value against the domain's
 * own enums. Splitting that would mean two places deciding what a valid status
 * is, and the second one is always the one that drifts.
 */
final class TrackIndexController
{
    public function __invoke(Request $request, TrackIndexQuery $tracks): Response
    {
        $filters = [
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'source' => $request->query('source'),
            'language' => $request->query('language'),
            'instrumental' => $request->query('instrumental'),
            'explicit' => $request->query('explicit'),
            'artist' => $request->query('artist'),
        ];

        $cursor = $request->query('cursor');

        return Inertia::render('Catalog/Tracks/Index', [
            'page' => $tracks->paginate($filters, is_string($cursor) ? $cursor : null),
            // Echoed back so the form shows what is actually applied rather
            // than what was typed — an unrecognised status narrows nothing, and
            // a form that still displayed it would be lying about the result.
            'filters' => array_map(fn ($value): ?string => is_string($value) ? $value : null, $filters),
            'options' => $tracks->options(),
        ]);
    }
}
