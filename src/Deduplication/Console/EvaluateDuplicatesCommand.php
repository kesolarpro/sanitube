<?php

declare(strict_types=1);

namespace SaniTube\Deduplication\Console;

use Illuminate\Console\Command;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Deduplication\Services\EvaluateAssetDuplicates;
use SaniTube\Operations\Exceptions\WorkRefused;
use SaniTube\Operations\Services\WorkAdmission;

/**
 * Compares a library that was imported before any of this existed.
 *
 * New assets are evaluated as they arrive. This is for everything that was
 * already there — which, on any installation that has been running a while, is
 * all of it.
 *
 * **It asks the gates first.** OPS-002 exists because a sweep over fifty
 * thousand assets is exactly the shape of work that takes a shared account
 * down, and a backfill that ignored the switch an operator just pressed would
 * be the first thing to make that switch untrustworthy. Evaluation runs inline
 * here rather than enqueuing one job per asset, so the admission asks for one
 * unit of work and the ceiling is about what is *already* queued.
 *
 * `--limit` is the honest default rather than an escape hatch: a first run on a
 * large library should finish, be looked at, and be run again, instead of
 * discovering after forty minutes that the thresholds were wrong.
 */
final class EvaluateDuplicatesCommand extends Command
{
    protected $signature = 'sanitube:duplicates:evaluate
        {--limit=500 : how many assets to look at, 0 for all}
        {--dry-run : report what would be evaluated and write nothing}';

    protected $description = 'Look for duplicates among assets that were imported before deduplication existed.';

    public function handle(EvaluateAssetDuplicates $evaluate, WorkAdmission $admission): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun) {
            try {
                $admission->admit(1);
            } catch (WorkRefused $refused) {
                // Named, not swallowed. An operator who paused the platform an
                // hour ago and forgot needs to be told which gate stopped them.
                $this->error(sprintf('Refused: %s', $refused->reason));

                return self::FAILURE;
            }
        }

        $limit = max(0, (int) $this->option('limit'));

        $query = Asset::query()
            ->whereIn('status', [AssetStatus::Stored->value, AssetStatus::Verified->value])
            ->whereNull('trashed_at')
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $assets = $query->get();

        if ($dryRun) {
            $this->info(sprintf('%d asset(s) would be evaluated. Nothing was written.', $assets->count()));

            return self::SUCCESS;
        }

        $findings = 0;

        foreach ($assets as $asset) {
            $findings += $evaluate->for($asset)->count();
        }

        $this->info(sprintf('Evaluated %d asset(s); %d finding(s) are on the queue.', $assets->count(), $findings));

        // Said plainly, because the number above is the one an operator will
        // otherwise read as "duplicates found and dealt with".
        $this->line('Nothing was deleted and nothing was decided. Findings wait for a person.');

        return self::SUCCESS;
    }
}
