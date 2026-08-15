<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Observers;

use SaniTube\Ingestion\Models\IngestionItem;

/**
 * Keeps `active_marker` honest.
 *
 * The idempotency guarantee is a unique index on `(ingestion_key,
 * active_marker)`, and it only means anything if the marker really does track
 * whether the item is in flight. Leaving that to callers would mean every
 * future write path — a retry, a cancellation, an admin fixing a stuck row —
 * has to remember, and the one that forgets either blocks re-import forever or
 * lets two workers process the same source at once.
 *
 * So it is derived from `status` here, on every save, and is not something
 * anyone sets by hand.
 */
final class IngestionItemObserver
{
    public function saving(IngestionItem $item): void
    {
        $item->setAttribute(
            'active_marker',
            $item->status->isTerminal() ? null : IngestionItem::ACTIVE,
        );
    }
}
