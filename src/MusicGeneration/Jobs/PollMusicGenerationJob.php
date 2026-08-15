<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SaniTube\MusicGeneration\Models\MusicGeneration;
use SaniTube\MusicGeneration\Services\PollMusicGeneration;

/**
 * Asks the provider what became of one generation, then schedules the next ask.
 *
 * **The re-dispatch is bounded, and that is the whole design.** A job that
 * queues itself is one provider outage away from running for ever, so it
 * re-dispatches only while the generation is still pollable — and
 * {@see PollMusicGeneration} fails the generation once `max_polls` is reached,
 * which makes the loop terminate on the other side too. Two independent
 * bounds, because a self-dispatching job with one bound is a job with one bug
 * away from none.
 */
final class PollMusicGenerationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $generationUuid) {}

    public function handle(PollMusicGeneration $poller): void
    {
        $generation = MusicGeneration::query()->where('uuid', $this->generationUuid)->first();

        if (! $generation instanceof MusicGeneration) {
            return;
        }

        $polled = $poller->handle($generation);

        if (! $polled->status->isPollable()) {
            // Finished, failed, cancelled, or never submitted. Nothing left
            // to ask about.
            return;
        }

        self::dispatch($this->generationUuid)
            ->delay(now()->addSeconds(max(1, (int) config('generation.poll.interval_seconds', 30))));
    }
}
