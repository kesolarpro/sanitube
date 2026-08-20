<?php

declare(strict_types=1);

namespace SaniTube\Artwork\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SaniTube\Artwork\Models\ArtworkGeneration;
use SaniTube\Artwork\Services\SubmitArtworkGeneration;
use SaniTube\Foundation\Concerns\SafelyRetryable;

/**
 * One cover request, one job.
 *
 * **Carries the uuid, not the model.** A serialised model is a snapshot taken
 * when the job was queued; by the time a worker reaches it the request may have
 * been cancelled, or already submitted by a redelivery of this same job.
 * Re-reading the row is what makes the claim in the submitter mean anything.
 *
 * **`tries` is 1, and that is deliberate.** Every other job in this platform
 * retries; this one calls a paid provider, and Laravel's retry is a blunt
 * instrument that cannot tell "the provider refused" from "the worker died
 * after the provider charged us". The claim already makes a redelivery safe,
 * and a person retrying a failed request is a decision somebody made rather
 * than one a queue made on their behalf.
 */
final class GenerateArtworkJob implements SafelyRetryable, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $generationUuid) {}

    public function tries(): int
    {
        return 1;
    }

    public function handle(SubmitArtworkGeneration $submitter): void
    {
        $generation = ArtworkGeneration::query()
            ->where('uuid', $this->generationUuid)
            ->first();

        if (! $generation instanceof ArtworkGeneration) {
            // Cancelled and cleaned up between queueing and running. Nothing
            // to do and nothing wrong.
            return;
        }

        // Every refusal is recorded on the row rather than thrown. A ceiling
        // reached, a paused installation or an open circuit is an ordinary
        // outcome of asking; throwing would bury a routine answer in the
        // failed-jobs table.
        $submitter->handle($generation);
    }
}
