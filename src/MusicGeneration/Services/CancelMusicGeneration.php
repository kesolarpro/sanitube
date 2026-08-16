<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Services;

use Illuminate\Support\Carbon;
use SaniTube\MusicGeneration\Enums\MusicGenerationStatus;
use SaniTube\MusicGeneration\Exceptions\GenerationException;
use SaniTube\MusicGeneration\Models\MusicGeneration;
use SaniTube\MusicGeneration\MusicGenerationManager;
use Throwable;

/**
 * Closes a generation locally, and asks the provider to stop if it can.
 *
 * **Extracted, not invented.** This behaviour existed and lived inside
 * `MusicGenerationController::cancel()`. A second surface needing it would have
 * meant a second copy of "tell the provider, then write CANCELLED", and the
 * copy that drifts is always the one nobody is looking at. Both the API and the
 * interface now call this; the semantics are unchanged.
 *
 * Two properties are load-bearing.
 *
 * **The provider call is best effort.** A provider that refuses, that does not
 * support cancellation, or that is simply unreachable must not stop SaniTube
 * recording the intent — otherwise a job stuck at a supplier could never be
 * closed here, and the operator would be looking at PROCESSING forever with no
 * way to act on it. The local record is what the platform can actually control.
 *
 * **A finished generation is not cancellable.** COMPLETED, FAILED and CANCELLED
 * are terminal, and "cancelling" one would rewrite the history of something
 * that already happened — including, for a COMPLETED one, hiding audio the
 * operator has already been billed for.
 */
final readonly class CancelMusicGeneration
{
    public function __construct(private MusicGenerationManager $providers) {}

    public function handle(MusicGeneration $generation): MusicGeneration
    {
        if (! $generation->status->isCancellable()) {
            throw GenerationException::notCancellable($generation->status->value);
        }

        if ($generation->provider_job_id !== null) {
            $this->askProviderToStop($generation);
        }

        $generation->forceFill([
            'status' => MusicGenerationStatus::Cancelled,
            'completed_at' => Carbon::now(),
        ])->save();

        return $generation->refresh();
    }

    /**
     * Ask, and carry on regardless of the answer.
     *
     * The throwable is swallowed deliberately and this is the one place in the
     * module where that is right: the alternative is that an unreachable
     * supplier makes a local record permanently uneditable.
     */
    private function askProviderToStop(MusicGeneration $generation): void
    {
        try {
            $this->providers
                ->provider($generation->provider)
                ->cancelGeneration((string) $generation->provider_job_id);
        } catch (Throwable) {
            // Intentionally ignored. See the class comment.
        }
    }
}
