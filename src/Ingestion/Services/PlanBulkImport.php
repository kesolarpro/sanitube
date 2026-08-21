<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Services;

use SaniTube\Ingestion\Enums\IngestionItemStatus;
use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Models\IngestionItem;

/**
 * Working out what is left to import.
 *
 * BULK-001 made nine hundred files importable, in batches of five hundred, and
 * left one thing out that only shows up on a real catalogue: **there was no way
 * to ask for the second five hundred.** `--prefix` takes everything under a
 * folder and a selection over the batch limit is refused outright, so a four
 * thousand file catalogue could be listed, refused, and imported no further
 * without naming three and a half thousand object keys by hand. The documented
 * remedy — "two batches of five hundred" — was not reachable from any
 * interface.
 *
 * This is the missing half. It answers *what has this installation not dealt
 * with yet*, and the answer is read from the items table rather than from a
 * cursor an operator has to keep: the same command, run again, takes the next
 * batch, and running it once more after the catalogue is in does nothing at
 * all.
 *
 * **Failed is not handled.** An item that failed is exactly the one a second
 * run should pick up — a network blip, a provider outage, a file that was not
 * there yet. Treating it as done would mean the only way to retry a failure was
 * to find it by hand, which for a catalogue-scale import is the same as never.
 *
 * **Skipped is not handled either.** Skipped means the work was *not
 * attempted*, because something else held the same intent at the time. The item
 * that actually did the work is a different row, and it is that row — if it
 * exists — that makes the reference handled.
 */
final readonly class PlanBulkImport
{
    /**
     * How many keys go into one `where in`.
     *
     * Well under every engine's parameter ceiling, including the one SQLite
     * shipped with for years. A catalogue import is not the place to discover
     * that a query works on MySQL and silently truncates elsewhere.
     */
    private const KEYS_PER_QUERY = 500;

    /**
     * @param  list<string>  $references  the whole selection, in a stable order
     * @param  int  $limit  the most this run may take
     */
    public function for(IngestionSource $source, array $references, int $limit): BulkImportPlan
    {
        $found = count($references);
        $handled = $this->handled($source, $references);

        $outstanding = array_values(array_filter(
            $references,
            static fn (string $reference): bool => ! isset($handled[$reference]),
        ));

        $taking = array_slice($outstanding, 0, max(0, $limit));

        return new BulkImportPlan(
            found: $found,
            alreadyHandled: count($handled),
            references: $taking,
            remaining: count($outstanding) - count($taking),
        );
    }

    /**
     * The references this installation has already dealt with.
     *
     * Keyed by reference rather than returned as a list, so the caller filters
     * by lookup instead of by scanning — at four thousand references the
     * difference between the two is measured in seconds.
     *
     * @param  list<string>  $references
     * @return array<string, true>
     */
    private function handled(IngestionSource $source, array $references): array
    {
        $byKey = [];

        foreach ($references as $reference) {
            $byKey[IngestionKey::for($source, $reference)][] = $reference;
        }

        $handled = [];

        foreach (array_chunk(array_keys($byKey), self::KEYS_PER_QUERY) as $chunk) {
            $rows = IngestionItem::query()
                ->whereIn('ingestion_key', $chunk)
                ->whereIn('status', self::handledStatuses())
                ->pluck('ingestion_key');

            foreach ($rows as $key) {
                foreach ($byKey[(string) $key] ?? [] as $reference) {
                    $handled[$reference] = true;
                }
            }
        }

        return $handled;
    }

    /**
     * @return list<string>
     */
    private static function handledStatuses(): array
    {
        $statuses = [];

        foreach (IngestionItemStatus::cases() as $status) {
            // Everything except the two that mean "ask again": FAILED, which
            // is what a retry is for, and SKIPPED, which means the work was
            // never attempted at all.
            if ($status === IngestionItemStatus::Failed || $status === IngestionItemStatus::Skipped) {
                continue;
            }

            $statuses[] = $status->value;
        }

        return $statuses;
    }
}
