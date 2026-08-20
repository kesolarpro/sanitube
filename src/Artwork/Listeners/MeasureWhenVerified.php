<?php

declare(strict_types=1);

namespace SaniTube\Artwork\Listeners;

use SaniTube\Artwork\Jobs\MeasureArtworkJob;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Events\AssetVerified;

/**
 * Queues a measurement when artwork is verified.
 *
 * **On verification rather than on storage**, because measuring bytes nobody
 * has confirmed would describe an object that may not be the one the catalogue
 * means — and the measurement would then have to be taken again anyway.
 *
 * A listener rather than a call inside the asset pipeline, so that storing a
 * file stays ignorant of whether this installation can measure images at all.
 * An install that cannot runs exactly the same upload; the release validator
 * simply reports that nobody has looked.
 *
 * Filtered here rather than only in the service. The service refuses
 * non-artwork too — that is the guarantee — but queueing a job for every
 * master in a 900-file import so it can decline is a queue nobody needed.
 */
final readonly class MeasureWhenVerified
{
    public function handle(AssetVerified $event): void
    {
        if ($event->asset->kind !== AssetKind::Artwork) {
            return;
        }

        MeasureArtworkJob::dispatch($event->asset->uuid);
    }
}
