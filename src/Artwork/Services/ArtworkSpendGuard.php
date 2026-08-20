<?php

declare(strict_types=1);

namespace SaniTube\Artwork\Services;

use Illuminate\Support\Carbon;
use SaniTube\Artwork\Models\ArtworkGeneration;
use SaniTube\MusicGeneration\Services\GenerationSpendGuard;

/**
 * How many more covers this installation is willing to ask for.
 *
 * **Operational safety, not finance.** It counts *requests*, because requests
 * are what this platform can observe. It holds no price, no currency, no
 * balance and no invoice, and it is deliberately not a step towards any of
 * those. The question is "should we keep going", which is an operations
 * question, and the answer is a number of requests.
 *
 * The same shape {@see GenerationSpendGuard} uses for music, and the same
 * reasoning applies with one difference worth stating: a cover is cheap next to
 * a track, but a release proposal engine that regenerates artwork until it
 * likes one is a loop with no natural end. The ceiling is what ends it.
 *
 * **The ledger is `artwork_generations` itself.** No second table, because
 * there is nothing a second table would know: `submitted_at` is written exactly
 * when a request left this server for a provider. A parallel counter would be a
 * second source of truth and would drift the first time a row was removed by
 * hand.
 *
 * That column also keeps the count honest in the other direction. A request
 * refused before submission — no provider, requirements unreachable, a ceiling
 * like this one, a claim lost to another worker — has no `submitted_at`, cost
 * nothing, and does not count against the ceiling that produced it.
 *
 * **Rolling windows, not calendar ones.** A calendar month needs a time zone to
 * be meaningful and a reset somebody has to remember. "The last 24 hours" needs
 * neither and cannot be gamed by starting a run at 23:55.
 */
final readonly class ArtworkSpendGuard
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
     * operator who has decided what they are willing to spend.
     */
    public function ceiling(string $window): int
    {
        return max(0, (int) config('artwork.limits.'.$window, 0));
    }

    /**
     * Requests that actually reached a provider.
     *
     * Counted by `submitted_at`, never by status. A generation that failed
     * after submission still cost a request; one refused before submission cost
     * nothing. Counting by outcome gets both wrong, in the direction somebody
     * pays for.
     *
     * Computed in PHP and passed as a bound value rather than subtracted in
     * SQL — ADR-0017, and the same rule every window in this platform follows.
     */
    private function submissionsSince(int $hours): int
    {
        return ArtworkGeneration::query()
            ->whereNotNull('submitted_at')
            ->where('submitted_at', '>=', Carbon::now()->subHours($hours))
            ->count();
    }
}
