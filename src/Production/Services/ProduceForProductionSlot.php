<?php

declare(strict_types=1);

namespace SaniTube\Production\Services;

use SaniTube\Editorial\Models\EditorialProfile;
use SaniTube\MusicGeneration\Enums\GenerationCapability;
use SaniTube\MusicGeneration\Exceptions\GenerationException;
use SaniTube\MusicGeneration\Exceptions\UnknownGenerationProvider;
use SaniTube\MusicGeneration\Jobs\SubmitMusicGenerationJob;
use SaniTube\MusicGeneration\Models\MusicGeneration;
use SaniTube\MusicGeneration\Services\SelectGenerationProvider;
use SaniTube\MusicGeneration\Services\StartMusicGeneration;
use SaniTube\Production\Models\ProductionPlan;
use SaniTube\Production\Models\ProductionSlot;

/**
 * Turning an occasion into a request.
 *
 * PROD-003 stopped one step short on purpose: {@see WorkProductionSlot} decides
 * that generating is warranted and leaves the slot CLAIMED, because "the slot
 * stays CLAIMED: what happens next is generation". Nothing was that next thing.
 * Until this class, the entire production engine could decide and never act,
 * and no test could have told the difference between a planner that worked and
 * one that did nothing at all.
 *
 * **The slot is not completed here.** Starting a generation is not producing a
 * track — the audio takes minutes, arrives asynchronously and can fail — so the
 * occasion stays CLAIMED with the generation recorded against it, and
 * {@see SettleProductionSlot} closes it when there is something to close it
 * with. Completing here would record that something was made before anything
 * was, which is the mistake the whole slot lifecycle exists to prevent.
 *
 * **A refusal that resolves itself is a skip; one that needs a person is a
 * failure.** A ceiling is rolling and a circuit closes on its own, so an
 * occasion that passed during one is the system working — and filing those
 * under failures teaches an operator to ignore the failure list. An
 * unconfigured or incapable provider does not fix itself and belongs in the
 * list somebody reads.
 */
final readonly class ProduceForProductionSlot
{
    /**
     * Refusals that the passage of time undoes.
     *
     * Nobody has to do anything about these, so the occasion is recorded as
     * having passed rather than as having gone wrong.
     */
    private const RESOLVES_ITSELF = ['CEILING_REACHED', 'PROVIDER_CIRCUIT_OPEN'];

    /**
     * Refusals that are about *this* supplier rather than the installation.
     *
     * These are the ones worth trying somebody else for. A ceiling is
     * deliberately absent: it belongs to the installation, and moving the
     * request to a second supplier to get past it would defeat it.
     */
    private const WORTH_ANOTHER_SUPPLIER = ['PROVIDER_UNAVAILABLE', 'PROVIDER_INCAPABLE', 'PROVIDER_CIRCUIT_OPEN'];

    public function __construct(
        private WorkProductionSlot $work,
        private ClaimProductionSlot $claims,
        private SelectGenerationProvider $selection,
        private StartMusicGeneration $starter,
    ) {}

    /**
     * @return MusicGeneration|null the generation this occasion started, or null when the
     *                              slot was not taken, was skipped, or nobody would serve it
     */
    public function handle(ProductionSlot $slot, string $worker): ?MusicGeneration
    {
        $inventory = $this->work->handle($slot, $worker);

        if ($inventory === null) {
            // Not taken, not due, not runnable, not warranted. All ordinary,
            // and all already settled or left alone by the decision itself.
            return null;
        }

        $slot->refresh();
        $plan = $slot->plan;

        if (! $plan instanceof ProductionPlan) {
            // Deleted between the decision and here. The claim is real, so it
            // has to be settled rather than left holding an occasion nobody
            // can act on.
            $this->claims->fail($slot, 'PLAN_NOT_RUNNABLE');

            return null;
        }

        return $this->start($slot, $plan);
    }

    /**
     * Try each supplier that could serve this occasion, most preferred first.
     *
     * The loop is the mandate's requirement that a provider failure must not
     * lose an occasion. Every supplier offered is written down before it is
     * asked, so a re-route after a failure discovered minutes later does not
     * go back to the one that just failed.
     */
    private function start(ProductionSlot $slot, ProductionPlan $plan): ?MusicGeneration
    {
        $prompt = $this->prompt($plan);
        $required = StartMusicGeneration::requires(null, false);
        $refusal = null;

        while (true) {
            $candidates = $this->selection->candidates($required, $slot->attempted());

            if ($candidates === []) {
                break;
            }

            $name = $candidates[0];
            $this->recordAttempt($slot, $name);

            try {
                $generation = $this->starter->handle(
                    prompt: $prompt,
                    // Named, so the row records the supplier this occasion was
                    // actually offered to rather than the one selection would
                    // pick again a second later.
                    providerName: $name,
                );
            } catch (GenerationException $exception) {
                $refusal = $exception;

                if (! in_array($exception->reason, self::WORTH_ANOTHER_SUPPLIER, true)) {
                    break;
                }

                continue;
            } catch (UnknownGenerationProvider) {
                // Named in configuration between the candidate list and here
                // and no longer resolvable. Recorded as attempted, so the loop
                // moves on rather than spinning.
                continue;
            }

            $slot->forceFill(['music_generation_id' => $generation->getKey()])->save();

            // Queued, not called. The audio takes minutes and this command
            // must not; and the slot now carries the handle that lets it be
            // settled by whatever the provider eventually says.
            SubmitMusicGenerationJob::dispatch($generation->uuid);

            return $generation;
        }

        $refusal ??= $this->whyNobody($required, $slot->attempted());
        $reason = $refusal instanceof GenerationException ? $refusal->reason : 'NO_CAPABLE_PROVIDER';

        if (in_array($reason, self::RESOLVES_ITSELF, true)) {
            $this->claims->skip($slot, $reason);
        } else {
            $this->claims->fail($slot, $reason);
        }

        return null;
    }

    /**
     * Why the candidate list was empty.
     *
     * `candidates()` answers *whether* anybody would serve the occasion and
     * deliberately not *why not*, so an empty list on its own would settle the
     * slot with NO_CAPABLE_PROVIDER — true, and useless to the operator of a
     * fresh installation whose actual problem is that no supplier is
     * configured. Asking for the selection refusal gets the actionable reason.
     *
     * @param  list<GenerationCapability>  $required
     * @param  list<string>  $except
     */
    private function whyNobody(array $required, array $except): ?GenerationException
    {
        try {
            $this->selection->select($required, null, $except);
        } catch (GenerationException $exception) {
            return $exception;
        } catch (UnknownGenerationProvider) {
            return null;
        }

        // Somebody became viable between the candidate list and this question.
        // Rare, and not worth a second attempt inside a settle: the next run
        // finds the occasion still pending nothing.
        return null;
    }

    private function recordAttempt(ProductionSlot $slot, string $name): void
    {
        $attempted = $slot->attempted();
        $attempted[] = $name;

        $slot->forceFill(['attempted_providers' => array_values(array_unique($attempted))])->save();
    }

    /**
     * What to ask for.
     *
     * The plan's editorial profile and nothing invented here. A prompt written
     * in this class would be a second place an installation's voice is
     * decided, silently different from the one an operator can see and edit --
     * which is exactly what EDI-001 exists to prevent.
     *
     * A profile that says nothing useful yields its name, because a supplier
     * handed an empty prompt produces something, and something unasked-for is
     * worse than a refusal.
     */
    private function prompt(ProductionPlan $plan): string
    {
        $profile = $plan->editorialProfile;

        if (! $profile instanceof EditorialProfile) {
            // The relation is non-nullable in the schema, so this is the
            // deleted-underneath case rather than a plan without a voice.
            return $plan->name;
        }

        // Null when the profile says nothing useful -- see
        // EditorialProfile::guidance(). A supplier handed an empty prompt
        // produces something, and something unasked-for is worse than a name.
        $guidance = $profile->guidance();

        return $guidance !== null && trim($guidance) !== '' ? $guidance : $profile->name;
    }
}
