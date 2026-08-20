<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Services;

use Illuminate\Support\Carbon;
use SaniTube\MusicGeneration\Enums\MusicGenerationStatus;
use SaniTube\MusicGeneration\Models\MusicGeneration;
use SaniTube\MusicGeneration\Models\MusicGenerationResult;
use SaniTube\MusicGeneration\ProviderResult;

/**
 * Writing down the takes a generation produced.
 *
 * Extracted in GEN-007 because there are now two ways to arrive here — a
 * synchronous provider that answered at once, and a poll that found a finished
 * job — and two implementations of "a generation completed" would be two
 * implementations that quietly stop matching. The studio screen reads one
 * table; there must be one thing that writes it.
 *
 * `updateOrCreate` on `(generation, provider_result_id)`: a provider that is
 * polled twice, or a synchronous call redelivered after a crash, returns the
 * same takes, and the same take must not become two rows an operator has to
 * choose between.
 */
final readonly class RecordGenerationResults
{
    /**
     * @param  list<ProviderResult>  $results
     * @return int how many takes were written
     */
    public function handle(MusicGeneration $generation, array $results): int
    {
        foreach ($results as $result) {
            MusicGenerationResult::query()->updateOrCreate(
                [
                    'generation_id' => $generation->id,
                    'provider_result_id' => $result->providerResultId,
                ],
                [
                    'source_reference' => $result->sourceReference,
                    'title' => $result->title,
                    'duration_ms' => $result->durationMs,
                    'raw' => $result->raw === [] ? null : $result->raw,
                ],
            );
        }

        return count($results);
    }

    /**
     * Mark the generation finished, once its takes are written.
     *
     * Separate from writing them, and in that order: a row marked Completed
     * with nothing under it is one the studio offers to import and cannot.
     */
    public function complete(MusicGeneration $generation): MusicGeneration
    {
        $generation->forceFill([
            'status' => MusicGenerationStatus::Completed,
            'completed_at' => Carbon::now(),
        ])->save();

        return $generation->refresh();
    }
}
