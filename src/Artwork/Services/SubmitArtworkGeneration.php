<?php

declare(strict_types=1);

namespace SaniTube\Artwork\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use SaniTube\Artwork\Enums\ArtworkGenerationStatus;
use SaniTube\Artwork\Enums\ArtworkRefusal;
use SaniTube\Artwork\Exceptions\ArtworkGenerationException;
use SaniTube\Artwork\Models\ArtworkGeneration;
use Throwable;

/**
 * Where a cover request actually leaves this server.
 *
 * **The only place `submitted_at` is written**, which makes it the only place
 * that costs anything — and therefore the only entry the spend guard counts.
 *
 * **Claimed first, by a guarded UPDATE.** The same shape PROD-002 settled on:
 * a queue redelivers, two workers can hold the same job, and a paid call is the
 * worst thing to make twice. `update()` returning 1 is the claim; a worker that
 * does not win it returns the row untouched, because a lost race is a success —
 * the work is happening, just not here.
 *
 * The claim expires, so a worker killed mid-flight does not park a request for
 * ever. It is released after submission: keeping it would make an
 * already-submitted row look busy for the rest of the expiry window.
 *
 * **Every guard is re-asked here.** `RequestArtworkGeneration` ran when a person
 * clicked; this can run hours later with a hundred siblings ahead of it. The
 * ceiling that matters is the one true at the moment the request would leave.
 */
final readonly class SubmitArtworkGeneration
{
    public function __construct(
        private RequestArtworkGeneration $admission,
        private GenerateArtwork $generator,
    ) {}

    public function handle(ArtworkGeneration $generation): ArtworkGeneration
    {
        if ($generation->status->isTerminal() || $generation->wasSubmitted()) {
            // Already done, or already sent. Read first so the common
            // redelivery costs one SELECT rather than a write — but never
            // trusted on its own; the claim below is what decides.
            return $generation;
        }

        if (! $this->claim($generation)) {
            // Another worker holds this request. Returning the row as it
            // stands is correct.
            return $generation->refresh();
        }

        try {
            $this->admission->assertAllowed();
        } catch (ArtworkGenerationException $exception) {
            // Refused before anything left. Recorded as a failure of *this
            // request* so an operator can see why it did not run — but with no
            // `submitted_at`, so it costs nothing and is not counted.
            return $this->refuse($generation, $exception->refusal);
        }

        $generation->forceFill([
            'status' => ArtworkGenerationStatus::Submitted,
            'attempts' => $generation->attempts + 1,
            // Written immediately before the call, never after. A crash during
            // the call must leave a row that says a request was made, because
            // one was.
            'submitted_at' => Carbon::now(),
        ])->save();

        try {
            // The existing generator, unchanged: it calls the provider, writes
            // the bytes to a temporary file, stores them as an Asset and
            // verifies them. ART-004 wraps it in safety rather than replacing
            // it — a generated cover still becomes an Asset like any other, and
            // is measured by ART-001 the same way.
            $produced = $this->generator->produce($generation->prompt, $generation->quality);
        } catch (ArtworkGenerationException $exception) {
            return $this->refuse($generation, $exception->refusal, released: true);
        } catch (Throwable) {
            // The provider's exception text is not carried, for the reason
            // ProviderFailure gives: a client message quotes the request, and
            // the prompt is catalogue data.
            return $this->refuse($generation, ArtworkRefusal::ProviderFailed, released: true);
        }

        $generation->forceFill([
            'status' => ArtworkGenerationStatus::Completed,
            'asset_id' => $produced['asset']->getKey(),
            // Provenance: which model made this, when the provider says so.
            'model' => $produced['model'],
            'completed_at' => Carbon::now(),
            'claimed_at' => null,
            'failure_code' => null,
        ])->save();

        return $generation->refresh();
    }

    /**
     * A guarded UPDATE, atomic on every engine in the matrix.
     *
     * Read-then-write would let two workers both see an unclaimed row.
     */
    private function claim(ArtworkGeneration $generation): bool
    {
        // Cutoff computed in PHP and passed as a value — ADR-0017 forbids
        // subtracting from a timestamp in raw SQL across these engines.
        $cutoff = Carbon::now()->subSeconds($this->claimSeconds());

        return ArtworkGeneration::query()
            ->where('id', $generation->getKey())
            ->whereNull('submitted_at')
            ->where(function (Builder $query) use ($cutoff): void {
                $query->whereNull('claimed_at')->orWhere('claimed_at', '<=', $cutoff);
            })
            ->update(['claimed_at' => Carbon::now()]) === 1;
    }

    private function refuse(
        ArtworkGeneration $generation,
        ArtworkRefusal $refusal,
        bool $released = false,
    ): ArtworkGeneration {
        $generation->forceFill([
            'status' => ArtworkGenerationStatus::Failed,
            'failure_code' => $refusal,
            'completed_at' => Carbon::now(),
            // Released either way. A failed request that kept its claim would
            // be unretryable until the claim expired.
            'claimed_at' => null,
        ])->save();

        return $generation->refresh();
    }

    private function claimSeconds(): int
    {
        return max(1, (int) config('artwork.submission_claim_seconds', 900));
    }
}
