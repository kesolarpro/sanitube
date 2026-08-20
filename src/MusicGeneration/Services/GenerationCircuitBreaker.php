<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Services;

use Illuminate\Support\Carbon;
use SaniTube\AI\Services\AiCircuitBreaker;
use SaniTube\MusicGeneration\Enums\MusicGenerationStatus;
use SaniTube\MusicGeneration\Models\MusicGeneration;

/**
 * Stops asking a generation provider that has stopped answering.
 *
 * A vendor having an outage answers every request identically and immediately.
 * A production plan with a backlog will discover that once per slot — each one
 * a request that may still be billed — and burn the whole backlog in the
 * minutes before anybody notices. The breaker turns that into a handful of
 * failures and a wait.
 *
 * The same reasoning {@see AiCircuitBreaker} applies to
 * completions. Kept as a separate class rather than generalised: the two read
 * different ledgers with different notions of "attempted", and a shared
 * abstraction over that would have to be told which one it was looking at on
 * every call — which is the parameter, not an abstraction.
 *
 * **Derived from the ledger, with no state of its own.** A row or cache key
 * holding "the circuit is open" has two problems: it drifts from what actually
 * happened, and something has to reset it. Reading the last few submissions
 * answers the question directly and cannot disagree with the record.
 *
 * It is **half-open for free**. Once the cooldown passes the next request goes
 * through; if it fails it becomes the newest failure and the window restarts.
 * No probe state to get wrong.
 */
final readonly class GenerationCircuitBreaker
{
    public function isOpenFor(string $provider): bool
    {
        $threshold = $this->threshold();

        if ($threshold <= 0) {
            return false;
        }

        /** @var list<MusicGeneration> $recent */
        $recent = MusicGeneration::query()
            ->where('provider', $provider)
            // Only requests that actually reached the vendor say anything
            // about the vendor. A run of "no provider configured" rows is a
            // statement about this installation.
            ->whereNotNull('submitted_at')
            ->orderByDesc('id')
            ->limit($threshold)
            ->get()
            ->all();

        // Not enough history to have failed that many times in a row.
        if (count($recent) < $threshold) {
            return false;
        }

        foreach ($recent as $generation) {
            // Anything that is not a failure closes the circuit -- including a
            // generation still in flight, which is evidence the provider
            // accepted a request rather than evidence it refused one.
            if ($generation->status !== MusicGenerationStatus::Failed) {
                return false;
            }
        }

        $newest = $recent[0]->submitted_at;

        // A run of failures from last week is history, not an outage. Without
        // this the breaker latches permanently the first time a provider has a
        // bad afternoon and is then left unused.
        return $newest instanceof Carbon
            && $newest->greaterThan(Carbon::now()->subMinutes($this->cooldownMinutes()));
    }

    /**
     * How many consecutive failed submissions open the circuit.
     *
     * Zero disables the breaker — a legitimate setting on an installation that
     * would rather see every failure than have requests withheld.
     */
    public function threshold(): int
    {
        return max(0, (int) config('generation.circuit.consecutive_failures', 5));
    }

    public function cooldownMinutes(): int
    {
        return max(1, (int) config('generation.circuit.cooldown_minutes', 15));
    }
}
