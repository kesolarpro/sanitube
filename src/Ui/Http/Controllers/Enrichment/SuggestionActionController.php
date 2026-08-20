<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Enrichment;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use SaniTube\Assets\Models\Asset;
use SaniTube\Catalog\Exceptions\TrackMetadataException;
use SaniTube\Enrichment\Enums\EnrichmentRefusal;
use SaniTube\Enrichment\Exceptions\SuggestionDecisionException;
use SaniTube\Enrichment\Models\MetadataSuggestion;
use SaniTube\Enrichment\Services\DecideSuggestion;
use SaniTube\Enrichment\Services\RequestEnrichment;
use SaniTube\Operations\Exceptions\WorkRefused;
use SaniTube\Ui\Http\Requests\Enrichment\AcceptSuggestionRequest;

/**
 * The four things a reviewer can do with a suggestion.
 *
 * **Nothing here writes a column.** Accepting calls the enrichment service,
 * which calls the catalogue's own editor; `$track->update(['title' => ...])`
 * would look like the same thing and would skip the refusals that stop a
 * released track being rewritten and a language arriving as a word. A
 * controller that assigns catalogue state is a second, weaker domain service.
 *
 * **Accepting and rejecting are separate routes because they are separate
 * acts**, and regenerating is a third: it is neither an agreement nor a
 * disagreement, and routing it through a rejection would record a verdict
 * nobody gave — corrupting the one signal the platform has about whether a
 * prompt version is any good.
 *
 * Refusals come back as codes. The English inside an exception is written for a
 * developer reading a log; the interface translates the code.
 */
final class SuggestionActionController
{
    public function accept(
        AcceptSuggestionRequest $request,
        MetadataSuggestion $suggestion,
        DecideSuggestion $decisions,
    ): RedirectResponse {
        /** @var User $reviewer */
        $reviewer = $request->user();

        try {
            $decisions->accept($suggestion, $reviewer, $request->edited());
        } catch (SuggestionDecisionException|TrackMetadataException $exception) {
            // Two exception types, one error bag. A reviewer does not care
            // which layer refused; they care which code came back, and both
            // carry one.
            return back()->withErrors(['suggestion' => $exception->reason]);
        }

        return back();
    }

    public function reject(
        Request $request,
        MetadataSuggestion $suggestion,
        DecideSuggestion $decisions,
    ): RedirectResponse {
        /** @var User $reviewer */
        $reviewer = $request->user();

        try {
            $decisions->reject($suggestion, $reviewer);
        } catch (SuggestionDecisionException $exception) {
            return back()->withErrors(['suggestion' => $exception->reason]);
        }

        return back();
    }

    /**
     * Ask again, over the top of an answer nobody has decided.
     *
     * Keyed by the asset rather than by the suggestion, for the reason
     * DEDUP-003 keys trashing by the asset: what is being asked for is a new
     * suggestion about this recording, and tying that to the row that prompted
     * it would make the same recording un-regenerable from anywhere else.
     */
    public function regenerate(Asset $asset, RequestEnrichment $enrichment): RedirectResponse
    {
        try {
            $refusal = $enrichment->again($asset);
        } catch (WorkRefused $refused) {
            return back()->withErrors(['enrichment' => $refused->reason]);
        }

        return $refusal instanceof EnrichmentRefusal
            ? back()->withErrors(['enrichment' => $refusal->value])
            : back();
    }
}
