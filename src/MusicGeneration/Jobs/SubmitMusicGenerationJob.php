<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SaniTube\MusicGeneration\Models\MusicGeneration;
use SaniTube\MusicGeneration\Services\SubmitMusicGeneration;

/**
 * Hands one queued generation to its provider.
 *
 * Carries the uuid rather than the model, like every other job here: a
 * serialised model is a snapshot of a row taken when the job was queued, and
 * by the time a worker picks it up that snapshot can be a lie — this one in
 * particular, because a generation can be cancelled between queueing and
 * running.
 */
final class SubmitMusicGenerationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $generationUuid) {}

    public function handle(SubmitMusicGeneration $submitter): void
    {
        $generation = MusicGeneration::query()->where('uuid', $this->generationUuid)->first();

        if ($generation instanceof MusicGeneration) {
            $submitter->handle($generation);
        }
    }
}
