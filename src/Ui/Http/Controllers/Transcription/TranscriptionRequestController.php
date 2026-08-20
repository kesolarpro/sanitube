<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Transcription;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use SaniTube\Assets\Models\Asset;
use SaniTube\Operations\Exceptions\WorkRefused;
use SaniTube\Transcription\Enums\TranscriptionRefusal;
use SaniTube\Transcription\Services\RequestTranscription;

/**
 * Asking, from a screen, for one asset to be transcribed.
 *
 * A POST because it is a write that spends money. A GET would be prefetched by
 * a browser, replayed by a crawler, and billed both times.
 *
 * **Nothing here decides anything.** The controller reads a hint off the form
 * and hands the asset to the service that owns the gates; it does not check
 * whether a supplier exists, whether the asset is trashed or whether the queue
 * has room, because a controller that repeated those checks would be a second,
 * weaker copy of them and would drift from the one the queue actually enforces.
 *
 * Both refusal kinds come back as codes. The English inside an exception is
 * written for a developer reading a log; the interface translates the code.
 */
final class TranscriptionRequestController
{
    public function __invoke(Request $request, Asset $asset, RequestTranscription $transcription): RedirectResponse
    {
        $validated = $request->validate([
            // A hint, and validated as one: a two-or-three letter code, never
            // free text. The supplier is told what the operator believes, and
            // reports what it heard regardless.
            'language' => ['nullable', 'string', 'regex:/^[A-Za-z]{2,3}$/'],
        ]);

        $hint = isset($validated['language']) && is_string($validated['language'])
            ? strtolower($validated['language'])
            : null;

        try {
            $refusal = $transcription->for($asset, $hint);
        } catch (WorkRefused $refused) {
            return back()->withErrors(['transcription' => $refused->reason]);
        }

        return $refusal instanceof TranscriptionRefusal
            ? back()->withErrors(['transcription' => $refusal->value])
            : back();
    }
}
