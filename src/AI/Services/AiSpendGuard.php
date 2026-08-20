<?php

declare(strict_types=1);

namespace SaniTube\AI\Services;

use Illuminate\Support\Carbon;
use SaniTube\AI\Models\AiInvocation;
use SaniTube\Operations\Services\BacklogGuard;

/**
 * How many more calls this installation is willing to make.
 *
 * **Operational safety, not finance.** It counts *calls*, because calls are
 * what this platform can observe; it holds no prices, no currency, no balance
 * and no invoice, and it is deliberately not a step towards any of those. The
 * question it answers is "should we keep going", which is an operations
 * question, and the answer is a number of requests rather than an amount of
 * money.
 *
 * The failure it exists to prevent is specific and has already nearly happened
 * twice in this platform's design. A backfill over four thousand masters
 * enqueues four thousand jobs; OPS-002's ceiling bounds how many of those sit
 * on a queue at once and bounds nothing at all about how many reach a vendor
 * over the following week. A queue that drains steadily spends steadily, and
 * the first sign of it is an invoice.
 *
 * **Rolling windows, not calendar ones.** A calendar month needs a time zone to
 * be meaningful and a reset somebody has to remember, and both are things that
 * go wrong quietly. "The last 24 hours" needs neither and cannot be gamed by
 * running a sweep at 23:55.
 *
 * **Counted from the ledger rather than from a counter this platform keeps**,
 * for the reason {@see BacklogGuard} gives about
 * queue depth: a counter is a second source of truth and drifts the first time
 * a row is removed by hand.
 */
final readonly class AiSpendGuard
{
    /**
     * The windows, longest last. Order matters for reporting: an installation
     * that has hit both its daily and its monthly ceiling should be told about
     * the monthly one, because that is the one that will still be true
     * tomorrow.
     *
     * @var array<string, int> window name => hours
     */
    private const WINDOWS = [
        'daily' => 24,
        'weekly' => 168,
        'monthly' => 720,
    ];

    /**
     * The window whose ceiling has been reached, or null when there is room.
     *
     * A name rather than a boolean, because "you have run out" and "you have
     * run out until the 3rd" are different instructions.
     */
    public function exhaustedWindow(): ?string
    {
        $exhausted = null;

        foreach (self::WINDOWS as $window => $hours) {
            $ceiling = $this->ceiling($window);

            if ($ceiling > 0 && $this->attemptsSince($hours) >= $ceiling) {
                // Not returned immediately: the longest exhausted window is
                // the more useful answer, and it is the last one found.
                $exhausted = $window;
            }
        }

        return $exhausted;
    }

    public function allows(): bool
    {
        return $this->exhaustedWindow() === null;
    }

    /**
     * How many calls remain in each window, for a status screen.
     *
     * `null` means no ceiling is configured for that window, which is a
     * legitimate setting and is reported as such rather than as a very large
     * number.
     *
     * @return array<string, int|null>
     */
    public function remaining(): array
    {
        $remaining = [];

        foreach (self::WINDOWS as $window => $hours) {
            $ceiling = $this->ceiling($window);
            $remaining[$window] = $ceiling > 0
                ? max(0, $ceiling - $this->attemptsSince($hours))
                : null;
        }

        return $remaining;
    }

    /**
     * Zero means no ceiling, and is the shipped default.
     *
     * A platform that refused AI calls out of the box would be a platform
     * whose first experience of the feature is a refusal. The ceilings are for
     * an operator who has decided what they are willing to spend, and until
     * they decide, the pause switch and the backlog ceiling are the brakes.
     */
    public function ceiling(string $window): int
    {
        return max(0, (int) config('ai.limits.'.$window, 0));
    }

    /**
     * Calls that actually left this server.
     *
     * `attempted`, never `succeeded`. A vendor answering 500 cost a request;
     * a missing key cost nothing. Counting by outcome gets both wrong.
     */
    private function attemptsSince(int $hours): int
    {
        return AiInvocation::query()
            ->where('attempted', true)
            ->where('created_at', '>=', Carbon::now()->subHours($hours))
            ->count();
    }
}
