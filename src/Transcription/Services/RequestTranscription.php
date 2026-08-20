<?php

declare(strict_types=1);

namespace SaniTube\Transcription\Services;

use SaniTube\Assets\Models\Asset;
use SaniTube\Operations\Exceptions\WorkRefused;
use SaniTube\Operations\Services\WorkAdmission;
use SaniTube\Transcription\Enums\TranscriptionRefusal;
use SaniTube\Transcription\Jobs\TranscribeAssetJob;

/**
 * Asking for one asset to be transcribed.
 *
 * The single entry point. Every route into transcription — the button on a
 * screen, the listener that follows an upload, the backfill command — comes
 * through here, so the three gates are asked in the same order every time and
 * no caller can acquire the work without acquiring the guards.
 *
 * The order is deliberate. The installation's own switch first, because an
 * operator who paused the platform meant it; the queue's capacity second,
 * because that is a measurement rather than a decision; the asset last, because
 * it is the only one of the three whose answer is about *this* file.
 *
 * **Nothing is transcribed inside this call.** A supplier round-trip over a
 * spooled-down master does not belong in a web request, and a person who
 * pressed a button should get an answer rather than a timeout.
 */
final readonly class RequestTranscription
{
    public function __construct(
        private WorkAdmission $admission,
        private TranscriptionEligibility $eligibility,
    ) {}

    /**
     * @return TranscriptionRefusal|null null when the work was queued
     *
     * @throws WorkRefused when the installation is paused or the queue is full
     */
    public function for(Asset $asset, ?string $languageHint = null): ?TranscriptionRefusal
    {
        // Asked before the eligibility check, and before anything is queued.
        // One job, because that is honestly what this enqueues -- the backfill
        // is where a caller asks for many and is told there is no room.
        $this->admission->admit(1);

        $refusal = $this->eligibility->refusalFor($asset);

        if ($refusal instanceof TranscriptionRefusal) {
            return $refusal;
        }

        TranscribeAssetJob::dispatch($asset->uuid, $languageHint);

        return null;
    }
}
