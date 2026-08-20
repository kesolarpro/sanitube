<?php

declare(strict_types=1);

namespace SaniTube\Transcription\Events;

use Illuminate\Foundation\Events\Dispatchable;
use SaniTube\Transcription\Models\Transcript;

/**
 * A supplier has said what it heard, and the platform has written it down.
 *
 * The seam AI enrichment will hang off. It is an event rather than a direct
 * call so that Transcription stays ignorant of what a transcript is *for*: it
 * knows how to obtain one and where to put it, and nothing about genres, moods
 * or descriptions. The dependency points one way, exactly as
 * `AssetFingerprinted` keeps Media ignorant of deduplication.
 *
 * **It carries the transcript, not the text.** A listener that wants the words
 * reads them from the row, which is also where the provider, the model, the
 * version and the segments are — and an enrichment that quoted a payload
 * instead would have no way to say which stored evidence it was working from.
 */
final class AssetTranscribed
{
    use Dispatchable;

    public function __construct(public readonly Transcript $transcript) {}
}
