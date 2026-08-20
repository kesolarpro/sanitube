<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Enrichment;

use Illuminate\Http\RedirectResponse;
use SaniTube\Assets\Models\Asset;
use SaniTube\Enrichment\Enums\EnrichmentRefusal;
use SaniTube\Enrichment\Services\RequestEnrichment;
use SaniTube\Operations\Exceptions\WorkRefused;

/**
 * Asking, from a screen, for one recording to be described.
 *
 * A POST because it is a write that spends money, and a GET would be prefetched
 * by a browser and billed for the privilege.
 *
 * **The controller decides nothing.** It hands the asset to the service that
 * owns the gates — a controller that re-checked whether a model was configured,
 * whether the asset was trashed or whether there was evidence would be a
 * second, weaker copy of those checks, drifting from the one the queue
 * enforces.
 *
 * Both refusal kinds come back as codes. The English inside an exception is
 * written for a developer reading a log; the interface translates the code.
 */
final class EnrichmentRequestController
{
    public function __invoke(Asset $asset, RequestEnrichment $enrichment): RedirectResponse
    {
        try {
            $refusal = $enrichment->for($asset);
        } catch (WorkRefused $refused) {
            return back()->withErrors(['enrichment' => $refused->reason]);
        }

        return $refusal instanceof EnrichmentRefusal
            ? back()->withErrors(['enrichment' => $refusal->value])
            : back();
    }
}
