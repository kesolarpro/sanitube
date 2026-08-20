<?php

declare(strict_types=1);

namespace SaniTube\Transcription\Listeners;

use SaniTube\Assets\Events\AssetStored;
use SaniTube\Operations\Exceptions\WorkRefused;
use SaniTube\Transcription\Services\RequestTranscription;

/**
 * Transcribes a new asset — **only if this installation asked for that.**
 *
 * Off by default, and that default is the design rather than caution. Every
 * other listener on `AssetStored` performs work that is free and local: a
 * checksum comparison, a fingerprint. This one spends money on somebody else's
 * server, per file, and a library of four thousand masters would spend it four
 * thousand times before anybody had looked at the first transcript.
 *
 * So automatic transcription is a decision an operator makes once they know
 * what a transcript is worth to them, and until they make it the platform
 * transcribes only what somebody asks for. The switch is
 * `transcription.automatic`.
 *
 * A refusal is silent here, because there is nobody to tell. The gates have
 * already recorded why, and an upload is not the moment to fail over an
 * enrichment that was never required.
 */
final readonly class TranscribeWhenStored
{
    public function __construct(private RequestTranscription $request) {}

    public function handle(AssetStored $event): void
    {
        // No fallback default here on purpose. `config/transcription.php` is
        // where this switch's default lives, and a second copy of it in a
        // listener is one that can disagree with the first -- which is exactly
        // the sort of disagreement that ends with an installation spending
        // money it did not intend to.
        if (! (bool) config('transcription.automatic')) {
            return;
        }

        try {
            $this->request->for($event->asset);
        } catch (WorkRefused) {
            // The platform is paused or the queue is full. Both are the
            // system working; neither is a reason to fail an upload that has
            // already succeeded.
        }
    }
}
