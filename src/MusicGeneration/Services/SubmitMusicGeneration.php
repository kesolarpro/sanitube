<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use SaniTube\Localization\ContentLanguage;
use SaniTube\MusicGeneration\Contracts\MusicGenerationProvider;
use SaniTube\MusicGeneration\Contracts\SynchronousMusicGenerationProvider;
use SaniTube\MusicGeneration\Enums\ExecutionMode;
use SaniTube\MusicGeneration\Enums\MusicGenerationStatus;
use SaniTube\MusicGeneration\Exceptions\GenerationException;
use SaniTube\MusicGeneration\GenerationExecution;
use SaniTube\MusicGeneration\GenerationRequest;
use SaniTube\MusicGeneration\Models\MusicGeneration;
use SaniTube\MusicGeneration\MusicGenerationManager;
use SaniTube\MusicGeneration\ProviderFailure;
use Throwable;

/**
 * Hands a queued generation to its provider.
 *
 * **Exclusive, not merely idempotent.** `provider_job_id` makes a *finished*
 * submission idempotent — a redelivered job sees the identifier and stops. It
 * cannot make an *in-flight* one exclusive, because for the whole duration of
 * the call it guards, the column it guards on is still null. Two workers handed
 * the same job both read null, both call the provider, and the installation
 * pays twice for one generation while the second job identifier belongs to a
 * row nobody polls.
 *
 * So the submission is claimed first, by the guarded `UPDATE` PROD-002 uses for
 * production slots: one statement, decided by its affected-row count, atomic on
 * every engine in the matrix without a transaction or a lock. A worker that
 * does not win the claim returns the row untouched — a lost race is a success,
 * because somebody else is doing the work.
 *
 * A claim expires. Its holder can die between claiming and answering, and a
 * permanent claim would leave that generation QUEUED for ever with nothing able
 * to pick it up; `generation.submission_claim_seconds` bounds how long a
 * crashed worker can hold one. The window is a trade rather than a fix: too
 * short and a slow provider gets asked twice, too long and a crash stalls a
 * generation. It is configurable for exactly that reason.
 *
 * Nothing a provider or an HTTP client says is written to `failure_reason`
 * directly. See {@see ProviderFailure} — that string reaches a browser, and a
 * client exception quotes the request that produced it.
 */
final readonly class SubmitMusicGeneration
{
    public function __construct(
        private MusicGenerationManager $providers,
        private GenerationSpendGuard $ceiling,
        private GenerationCircuitBreaker $breaker,
        private RecordGenerationResults $results,
    ) {}

    public function handle(MusicGeneration $generation): MusicGeneration
    {
        if ($generation->submitted_at !== null || $generation->status->isTerminal()) {
            // Already submitted, or cancelled before a worker got to it. Read
            // first so the common redelivery costs one SELECT rather than a
            // write, but never trusted on its own -- the claim below is what
            // actually decides.
            //
            // **`submitted_at`, not `provider_job_id`.** GEN-007 made the
            // distinction load-bearing: a synchronous provider returns no job
            // identifier and never will, so a guard keyed on that column would
            // let every redelivery call such a supplier again. `submitted_at`
            // is written exactly when a request leaves this server, which is
            // the question being asked, and it is already what the spend guard
            // counts.
            return $generation;
        }

        if (! $this->claim($generation)) {
            // Another worker holds this submission. Returning the row as it
            // stands is correct: the work is happening, just not here.
            return $generation->refresh();
        }

        $provider = $this->providers->provider($generation->provider);

        if (! $provider->isAvailable()) {
            return $this->fail($generation, 'The provider is not configured on this installation.');
        }

        // Checked again here, and this is the check that counts: StartMusicGeneration
        // ran when a person clicked, and a queue can run this hours later with a
        // thousand siblings ahead of it. The ceiling that matters is the one
        // read at the moment the request would leave.
        $exhausted = $this->ceiling->exhaustedWindow();

        if ($exhausted !== null) {
            return $this->fail($generation, GenerationException::ceilingReached($exhausted)->getMessage());
        }

        if ($this->breaker->isOpenFor($provider->name())) {
            return $this->fail($generation, GenerationException::providerCircuitOpen($provider->name())->getMessage());
        }

        $request = new GenerationRequest(
            prompt: $generation->prompt,
            stylePrompt: $generation->style_prompt,
            lyrics: $generation->lyrics,
            instrumental: $generation->instrumental,
            language: $generation->language_code === ContentLanguage::UNKNOWN
                ? null
                : ContentLanguage::fromCode($generation->language_code),
            model: $generation->model,
            parameters: $this->scalarParameters($generation),
        );

        try {
            $execution = $this->execute($provider, $request);
        } catch (Throwable $exception) {
            // A provider outage is an ordinary condition for an optional
            // feature. The generation fails; the worker does not.
            //
            // The message is not carried. It describes the request -- URI,
            // headers, and on Laravel's RequestException the response body too
            // -- and this string is rendered on the Studio screen.
            return $this->fail($generation, ProviderFailure::fromThrowable($provider->name(), $exception));
        }

        if ($execution === null) {
            // Two different faults, and they get two different sentences. An
            // asynchronous supplier that answered without an identifier has a
            // bug; a provider that implements neither execution contract has
            // not been finished. Telling an operator the second when the first
            // happened would send them to read the wrong file.
            return $this->fail($generation, ProviderFailure::fromProvider(
                $provider->name(),
                null,
                $provider instanceof MusicGenerationProvider
                    ? 'The provider returned no job identifier, so nothing could ever collect the result.'
                    : 'The provider cannot generate: it implements no execution contract.',
            ));
        }

        return $execution->finished
            ? $this->finish($generation, $execution)
            : $this->inFlight($generation, $execution);
    }

    /**
     * Ask the supplier, in whatever shape it has.
     *
     * The **only** place in the platform that branches on execution shape, and
     * the reason {@see GenerationExecution} exists: both roads end in one value
     * that says what happened, so everything downstream is written once.
     *
     * Synchronous is tried first. A supplier that implements both contracts has
     * offered a way to get the audio without a second round trip, and taking it
     * is strictly better: nothing to poll, nothing to lose track of, and no row
     * left PROCESSING if the queue stops.
     */
    private function execute(object $provider, GenerationRequest $request): ?GenerationExecution
    {
        if ($provider instanceof SynchronousMusicGenerationProvider) {
            return $provider->generate($request);
        }

        if ($provider instanceof MusicGenerationProvider) {
            $result = $provider->createGeneration($request);

            if (trim($result->providerJobId) === '') {
                // An asynchronous answer with no identifier can never be
                // collected. Distinguished from a synchronous answer, which
                // legitimately has none -- before GEN-007 the two were the same
                // branch, and that is what would have forced a synchronous
                // adapter to invent one.
                return null;
            }

            return GenerationExecution::inFlight(
                providerJobId: $result->providerJobId,
                model: $result->model,
            );
        }

        return null;
    }

    /**
     * A synchronous supplier answered with the audio.
     *
     * `provider_job_id` stays **null**, deliberately and for ever: there is no
     * job, so there is no identifier, and writing a made-up one would leave a
     * row that looks pollable to anything reading it later.
     */
    private function finish(MusicGeneration $generation, GenerationExecution $execution): MusicGeneration
    {
        $now = Carbon::now();

        $generation->forceFill([
            'submission_claimed_at' => null,
            'execution_mode' => ExecutionMode::Synchronous,
            'model' => $execution->model ?? $generation->model,
            // Written even though the work is already done. The spend guard
            // counts requests that left this server, and this one did.
            'submitted_at' => $now,
        ])->save();

        $this->results->handle($generation, $execution->results);

        return $this->results->complete($generation);
    }

    private function inFlight(MusicGeneration $generation, GenerationExecution $execution): MusicGeneration
    {
        $generation->forceFill([
            'provider_job_id' => $execution->providerJobId,
            // Released deliberately. The claim protected the call; keeping it
            // afterwards would make an already-submitted row look busy for the
            // rest of the expiry window.
            'submission_claimed_at' => null,
            'execution_mode' => $execution->mode,
            'status' => MusicGenerationStatus::Processing,
            'model' => $execution->model ?? $generation->model,
            'submitted_at' => Carbon::now(),
        ])->save();

        return $generation->refresh();
    }

    /**
     * @return array<string, scalar|null>
     */
    private function scalarParameters(MusicGeneration $generation): array
    {
        $parameters = [];

        foreach ($generation->parameters ?? [] as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $parameters[(string) $key] = $value;
            }
        }

        return $parameters;
    }

    /**
     * Take the exclusive right to submit this generation, if it is going.
     *
     * One statement, and the affected-row count is the answer. A read followed
     * by a write would reintroduce the window this exists to close.
     *
     * The cutoff is computed in PHP and bound as a value rather than derived in
     * SQL: ADR-0017's rule about arithmetic on columns applies to timestamps
     * too, and `NOW() - INTERVAL` is spelled differently on each engine in the
     * matrix.
     */
    private function claim(MusicGeneration $generation): bool
    {
        $cutoff = Carbon::now()->subSeconds($this->claimSeconds());

        return MusicGeneration::query()
            ->where('id', $generation->id)
            // GEN-007: `submitted_at`, for the same reason the early return
            // uses it. A synchronous provider never fills `provider_job_id`.
            ->whereNull('submitted_at')
            ->where(function (Builder $query) use ($cutoff): void {
                $query->whereNull('submission_claimed_at')
                    ->orWhere('submission_claimed_at', '<=', $cutoff);
            })
            ->update(['submission_claimed_at' => Carbon::now()]) === 1;
    }

    private function claimSeconds(): int
    {
        return max(1, (int) config('generation.submission_claim_seconds', 900));
    }

    private function fail(MusicGeneration $generation, string $reason): MusicGeneration
    {
        $generation->forceFill([
            'status' => MusicGenerationStatus::Failed,
            'failure_reason' => $reason,
            // A failed generation is finished. Holding the claim would stop
            // nothing and hide nothing; releasing it keeps "claimed" meaning
            // exactly one thing.
            'submission_claimed_at' => null,
            'completed_at' => Carbon::now(),
        ])->save();

        return $generation->refresh();
    }
}
