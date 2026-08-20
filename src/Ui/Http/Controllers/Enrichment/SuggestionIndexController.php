<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Enrichment;

use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Ui\Http\Requests\Enrichment\SuggestionIndexRequest;
use SaniTube\Ui\Queries\SuggestionReviewQuery;

/**
 * The enrichment review queue.
 *
 * Readable by anyone signed in. Knowing what a model proposed about a recording
 * is not a privilege; *acting* on it is, and those routes carry the guard.
 */
final class SuggestionIndexController
{
    public function __invoke(SuggestionIndexRequest $request, SuggestionReviewQuery $suggestions): Response
    {
        $filters = $request->filters();

        return Inertia::render('Enrichment/Index', [
            'page' => $suggestions->paginate($filters, $request->cursor()),
            'filters' => $filters,
            'options' => $suggestions->options(),
        ]);
    }
}
