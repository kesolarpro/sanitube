<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SaniTube\MusicGeneration\Contracts\MusicGenerationProvider;
use SaniTube\MusicGeneration\Enums\MusicGenerationStatus;
use SaniTube\MusicGeneration\GenerationStatus;
use SaniTube\MusicGeneration\Models\MusicGeneration;
use SaniTube\MusicGeneration\MusicGenerationManager;
use SaniTube\MusicGeneration\ProviderFailure;
use SaniTube\MusicGeneration\ProviderResult;
use Throwable;

/**
 * Asks the provider what became of a generation, and records the answer.
 *
 * **Bounded.** `poll_count` is incremented on every pass and compared against
 * `generation.poll.max_polls`; past it, the generation is failed with a
 * timeout. A provider that never answers must cost a fixed amount of work, and
 * a row left permanently QUEUED is a row nobody ever cleans up. This is also
 * what stops the polling job re-dispatching itself for ever.
 *
 * **The provider's own words are sanitised before they are stored.** A failure
 * reason is rendered on the Studio screen; a vendor explaining itself may quote
 * the request, and a signed callback URL's token is in no config file for a
 * redactor to recognise. See {@see ProviderFailure}.
 *
 * Results are written by `updateOrCreate` on `(generation, provider_result_id)`
 * — the unique index — because polling re-fetches the same list on every pass
 * and a plain insert would multiply the takes by the number of polls.
 */
final readonly class PollMusicGeneration
{
    public function __construct(
        private MusicGenerationManager $providers,
        private RecordGenerationResults $results,
    ) {}

    public function handle(MusicGeneration $generation): MusicGeneration
    {
        if (! $generation->status->isPollable() || $generation->provider_job_id === null) {
            // Terminal, or never submitted. Asking the provider about a job
            // identifier that does not exist answers nothing.
            return $generation;
        }

        // Incremented by the database rather than by reading and adding one.
        // Two workers polling the same generation both read N and both write
        // N+1, so the count advances by one per round instead of per poll --
        // and the bound that exists to stop a runaway loop is exactly the thing
        // a runaway loop would evade.
        MusicGeneration::query()
            ->where('id', $generation->id)
            ->update([
                'poll_count' => DB::raw('poll_count + 1'),
                'last_polled_at' => Carbon::now(),
            ]);

        $generation->refresh();

        if ($generation->poll_count > $this->maximumPolls()) {
            return $this->fail($generation, sprintf(
                'The provider did not finish after %d polls. Failing rather than leaving the '
                    .'generation in flight for ever.',
                $this->maximumPolls(),
            ));
        }

        $provider = $this->providers->provider($generation->provider);

        if (! $provider instanceof MusicGenerationProvider) {
            // GEN-007 widened what a provider may be. Only an asynchronous one
            // has a status to ask about; a synchronous generation never reaches
            // here, because it is Completed before anything could poll it. This
            // is the case where an installation swapped a provider name from an
            // asynchronous supplier to a synchronous one while a generation was
            // in flight -- rare, and better answered than crashed on.
            return $this->fail($generation, sprintf(
                'The [%s] provider no longer answers status questions, so this generation cannot be '
                    .'collected. It was submitted to a provider of a different kind.',
                $generation->provider,
            ));
        }

        try {
            $state = $provider->getGenerationStatus($generation->provider_job_id);
        } catch (Throwable $exception) {
            // A transient outage must not fail the generation: the next poll
            // may well succeed, and the poll bound is what limits the retrying.
            return $generation->refresh();
        }

        return match ($state->status) {
            GenerationStatus::Completed => $this->complete($generation, $provider->fetchResults($generation->provider_job_id)),
            GenerationStatus::Failed => $this->fail($generation, ProviderFailure::fromProvider(
                $generation->provider,
                $state->failureReason,
                'The provider reported a failure.',
            )),
            GenerationStatus::Cancelled => $this->cancelled($generation),
            GenerationStatus::Running => $this->mark($generation, MusicGenerationStatus::Processing),
            GenerationStatus::Pending => $generation->refresh(),
        };
    }

    /**
     * @param  list<ProviderResult>  $results
     */
    private function complete(MusicGeneration $generation, array $results): MusicGeneration
    {
        // GEN-007 moved the writing itself into a shared service, because a
        // synchronous provider arrives at the same place by another road and
        // two implementations of "a generation completed" would be two that
        // quietly stop matching.
        $this->results->handle($generation, $results);

        if ($results === []) {
            // A completed job with nothing to download is a provider fault,
            // not an empty track. Recorded as a failure so it is visible
            // rather than sitting as a completed generation with no takes.
            return $this->fail($generation, 'The provider reported success but returned no audio.');
        }

        return $this->results->complete($generation);
    }

    private function cancelled(MusicGeneration $generation): MusicGeneration
    {
        $generation->forceFill([
            'status' => MusicGenerationStatus::Cancelled,
            'completed_at' => Carbon::now(),
        ])->save();

        return $generation->refresh();
    }

    private function mark(MusicGeneration $generation, MusicGenerationStatus $status): MusicGeneration
    {
        $generation->forceFill(['status' => $status])->save();

        return $generation->refresh();
    }

    private function fail(MusicGeneration $generation, string $reason): MusicGeneration
    {
        $generation->forceFill([
            'status' => MusicGenerationStatus::Failed,
            'failure_reason' => $reason,
            'completed_at' => Carbon::now(),
        ])->save();

        return $generation->refresh();
    }

    private function maximumPolls(): int
    {
        return max(1, (int) config('generation.poll.max_polls', 60));
    }
}
