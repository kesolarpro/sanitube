<?php

declare(strict_types=1);

namespace SaniTube\AI\Services;

use Illuminate\Support\Carbon;
use SaniTube\AI\Models\AiInvocation;

/**
 * Stops asking a provider that has stopped answering.
 *
 * A vendor having an outage answers every request identically and immediately.
 * A queue worker with two thousand enrichment jobs will happily discover that
 * two thousand times — each one a request that may still be billed, each one a
 * row in the ledger, and the whole backlog burned through in the minutes before
 * anybody notices. The breaker turns that into a handful of failures and a
 * wait.
 *
 * **Derived from the ledger, with no state of its own.** The alternative is a
 * row or a cache key holding "the circuit is open", and both have the same two
 * problems: they drift from what actually happened, and they need something to
 * reset them. Reading the last few invocations answers the question directly
 * and cannot disagree with the record.
 *
 * It is also **half-open for free**. Once the cooldown passes the next call is
 * allowed through; if that one fails it becomes the newest failure and the
 * window starts again. There is no separate probe state to get wrong.
 *
 * Per provider, because an OpenAI outage is not a reason to stop asking
 * Anthropic.
 */
final readonly class AiCircuitBreaker
{
    public function isOpenFor(string $provider): bool
    {
        $threshold = $this->threshold();

        if ($threshold <= 0) {
            return false;
        }

        /** @var list<AiInvocation> $recent */
        $recent = AiInvocation::query()
            ->where('provider', $provider)
            // Only calls that actually reached the vendor say anything about
            // the vendor. A run of "no key configured" rows is a statement
            // about this installation.
            ->where('attempted', true)
            ->orderByDesc('id')
            ->limit($threshold)
            ->get()
            ->all();

        // Not enough history to have failed that many times in a row.
        if (count($recent) < $threshold) {
            return false;
        }

        foreach ($recent as $invocation) {
            if ($invocation->succeeded) {
                return false;
            }
        }

        $newest = $recent[0]->created_at;

        // A run of failures from last week is history, not an outage. Without
        // this the breaker would latch permanently the first time a provider
        // had a bad afternoon and was then left unused.
        return $newest instanceof Carbon
            && $newest->greaterThan(Carbon::now()->subMinutes($this->cooldownMinutes()));
    }

    /**
     * How many consecutive failed attempts open the circuit.
     *
     * Zero disables the breaker, which is a legitimate setting on an
     * installation that would rather see every failure than have calls
     * withheld.
     */
    public function threshold(): int
    {
        return max(0, (int) config('ai.circuit.consecutive_failures', 5));
    }

    public function cooldownMinutes(): int
    {
        return max(1, (int) config('ai.circuit.cooldown_minutes', 15));
    }
}
