<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SaniTube\MusicGeneration\Enums\ExecutionMode;
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
 *
 * **It also starts the polling**, and until GEN-007 nothing did.
 * {@see PollMusicGenerationJob} re-dispatches itself while a generation is
 * still in flight, but nothing in the platform ever dispatched the *first* one:
 * `grep` found it constructed only in a test. An asynchronous generation
 * therefore reached PROCESSING and was polled by nobody, sitting in flight for
 * ever with a supplier holding a finished job.
 *
 * Polling starts here rather than inside {@see SubmitMusicGeneration} because a
 * service that queued jobs would be a service a test cannot run without a
 * queue, and because the decision belongs to whatever is orchestrating: this
 * job knows it is running in a worker, and the service does not.
 *
 * A **synchronous** generation is never polled. It has no provider job
 * identifier and no job to ask about, and its execution mode says so — see
 * {@see ExecutionMode::isPollable()}.
 */
final class SubmitMusicGenerationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * One attempt.
     *
     * A submission that fails has either been refused or has cost a call, and
     * a queue retry cannot tell which — a timeout on a request the provider
     * actually received would be retried into a second billed generation. The
     * service claims the row before calling out, which makes a redelivery safe
     * rather than expensive; this makes redelivery rare as well. Retrying is a
     * decision for whoever is watching, not a default.
     */
    public int $tries = 1;

    public function __construct(public readonly string $generationUuid) {}

    public function handle(SubmitMusicGeneration $submitter): void
    {
        $generation = MusicGeneration::query()->where('uuid', $this->generationUuid)->first();

        if (! $generation instanceof MusicGeneration) {
            return;
        }

        $submitted = $submitter->handle($generation);

        if (! $submitted->status->isPollable() || $submitted->provider_job_id === null) {
            // Finished on the spot -- a synchronous supplier -- or refused, or
            // claimed by another worker that will do this itself. Nothing to
            // ask about.
            return;
        }

        if ($submitted->execution_mode !== null && ! $submitted->execution_mode->isPollable()) {
            // Belt and braces, and the honest reason rather than its
            // consequence: the mode is what says this supplier will not answer
            // a status question, and the absent identifier is why.
            return;
        }

        // The first poll. Delayed by the configured interval rather than
        // immediate: a supplier asked for its status in the same second it
        // accepted the work will answer "queued", which costs a call to learn
        // nothing.
        PollMusicGenerationJob::dispatch($this->generationUuid)
            ->delay(now()->addSeconds(max(1, (int) config('generation.poll.interval_seconds', 30))));
    }
}
