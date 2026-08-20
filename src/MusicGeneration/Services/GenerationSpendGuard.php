<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Services;

use Illuminate\Support\Carbon;
use SaniTube\AI\Services\AiSpendGuard;
use SaniTube\MusicGeneration\Models\MusicGeneration;

/**
 * How many more generations this installation is willing to ask for.
 *
 * **Operational safety, not finance.** It counts *requests*, because requests
 * are what this platform can observe. It holds no prices, no currency, no
 * balance and no invoice, and it is deliberately not a step towards any of
 * those. The question is "should we keep going", which is an operations
 * question, and the answer is a number of requests.
 *
 * The same guard {@see AiSpendGuard} provides for completions, for a reason
 * that applies here more sharply: PROD-001 through PROD-003 built a planner
 * whose entire purpose is to decide, unattended, that more music should exist.
 * A plan with a daily target and a queue that drains steadily spends steadily,
 * and the first sign of it is an invoice. A completion is cents; a generation
 * is not.
 *
 * **The ledger is `music_generations` itself.** There is no second table,
 * because there is nothing a second table would know: `submitted_at` is
 * written exactly when a request left this server for a provider, which is the
 * definition of the thing being counted. A parallel counter would be a second
 * source of truth and would drift the first time a row was removed by hand.
 *
 * That column is also what keeps the count honest in the other direction. A
 * generation refused before submission — no provider configured, a claim lost
 * to another worker, a ceiling like this one — has no `submitted_at` and cost
 * nothing, so it does not count against the ceiling that produced it.
 *
 * **Rolling windows, not calendar ones.** A calendar month needs a time zone to
 * be meaningful and a reset somebody has to remember. "The last 24 hours" needs
 * neither and cannot be gamed by starting a run at 23:55.
 */
final readonly class GenerationSpendGuard
{
    /**
     * Longest last. Order matters for reporting: an installation that has hit
     * both its daily and its monthly ceiling should be told about the monthly
     * one, because that is the one still true tomorrow.
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
     * run out until Tuesday" are different instructions.
     */
    public function exhaustedWindow(): ?string
    {
        $exhausted = null;

        foreach (self::WINDOWS as $window => $hours) {
            $ceiling = $this->ceiling($window);

            if ($ceiling > 0 && $this->submissionsSince($hours) >= $ceiling) {
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
     * How many requests remain in each window, for a status screen.
     *
     * `null` means no ceiling is configured for that window — a legitimate
     * setting, reported as such rather than as a very large number.
     *
     * @return array<string, int|null>
     */
    public function remaining(): array
    {
        $remaining = [];

        foreach (self::WINDOWS as $window => $hours) {
            $ceiling = $this->ceiling($window);
            $remaining[$window] = $ceiling > 0
                ? max(0, $ceiling - $this->submissionsSince($hours))
                : null;
        }

        return $remaining;
    }

    /**
     * Zero means no ceiling, and is the shipped default.
     *
     * A platform that refused generation out of the box would be one whose
     * first experience of the feature is a refusal. The ceiling is for an
     * operator who has decided what they are willing to spend; until they
     * decide, the production plan's own target is the brake.
     */
    public function ceiling(string $window): int
    {
        return max(0, (int) config('generation.limits.'.$window, 0));
    }

    /**
     * Requests that actually reached a provider.
     *
     * Counted by `submitted_at`, never by status. A generation that failed
     * after submission still cost a request; one refused before submission
     * cost nothing. Counting by outcome gets both wrong, in the direction
     * somebody pays for.
     */
    private function submissionsSince(int $hours): int
    {
        return MusicGeneration::query()
            ->whereNotNull('submitted_at')
            ->where('submitted_at', '>=', Carbon::now()->subHours($hours))
            ->count();
    }
}
