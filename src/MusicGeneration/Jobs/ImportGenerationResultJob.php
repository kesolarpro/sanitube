<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SaniTube\MusicGeneration\Models\MusicGenerationResult;
use SaniTube\MusicGeneration\Services\SelectMusicGenerationResult;

/**
 * Fetches a selected result's audio and turns it into a candidate.
 *
 * Queued rather than done in the request, because it downloads a rendered
 * master from a provider and nine hundred of those will not survive an HTTP
 * timeout. Safe to redeliver: selection is idempotent by `asset_id` and
 * `candidate_id` on the result row.
 */
final class ImportGenerationResultJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $resultUuid) {}

    public function handle(SelectMusicGenerationResult $selector): void
    {
        $result = MusicGenerationResult::query()->where('uuid', $this->resultUuid)->first();

        if ($result instanceof MusicGenerationResult) {
            $selector->handle($result);
        }
    }
}
