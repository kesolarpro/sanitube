<?php

declare(strict_types=1);

namespace SaniTube\Foundation\Database;

use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Whether a table is there, asked of the engine once per request.
 *
 * The answer cannot change while one page is being drawn, and the dashboard
 * asks about the same handful of tables repeatedly — a grouped count and a
 * filtered count over `audio_analyses` each check it first.
 *
 * **Measured before this existed: thirty-four queries to draw the dashboard,
 * eleven of them `sqlite_master` lookups asking the same three questions over
 * and over.** Cheap queries, but the dashboard is the landing page and they
 * were pure repetition.
 *
 * **Bound `scoped`, so the cache lives exactly one request.** A migration
 * between two page loads must be seen. A static cache would outlive the
 * request under a persistent worker and report a table as missing until the
 * process was restarted — the kind of staleness that gets diagnosed as a
 * database fault.
 *
 * A failure to ask is recorded as absent, which is what every caller already
 * does with the answer: the dashboard renders "unknown" rather than a zero,
 * because an operator who reads `0 failed jobs` concludes the queue is healthy.
 */
final class SchemaPresence
{
    /** @var array<string, bool> */
    private array $known = [];

    public function has(string $table): bool
    {
        if (array_key_exists($table, $this->known)) {
            return $this->known[$table];
        }

        try {
            return $this->known[$table] = Schema::hasTable($table);
        } catch (Throwable) {
            return $this->known[$table] = false;
        }
    }
}
