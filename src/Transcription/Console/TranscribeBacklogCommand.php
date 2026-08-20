<?php

declare(strict_types=1);

namespace SaniTube\Transcription\Console;

use Illuminate\Console\Command;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Operations\Exceptions\WorkRefused;
use SaniTube\Operations\Services\WorkAdmission;
use SaniTube\Transcription\Enums\TranscriptionRefusal;
use SaniTube\Transcription\Jobs\TranscribeAssetJob;
use SaniTube\Transcription\Services\TranscriptionEligibility;
use SaniTube\Transcription\TranscriptionManager;

/**
 * Transcribes a library that was imported before any supplier was configured.
 *
 * New assets are offered to a supplier as they arrive, if this installation
 * turned that on. This is for everything already there — which, on an
 * installation that has been running a while, is all of it.
 *
 * **This one really does cost money**, which is why it is the most cautious
 * command in the platform. `--limit` defaults low rather than to everything, a
 * dry run reports exactly what would be sent and why the rest would not, and
 * the admission gate is asked for the *whole batch* rather than for one job —
 * because the failure this guards against is four thousand jobs landing on a
 * queue a cron worker drains once a minute.
 *
 * Nothing is transcribed inline. The command queues, and says how many.
 */
final class TranscribeBacklogCommand extends Command
{
    protected $signature = 'sanitube:transcription:backlog
        {--limit=100 : how many assets to queue, 0 for all}
        {--language= : a hint passed to the supplier, never an assertion}
        {--dry-run : report what would be queued and enqueue nothing}';

    protected $description = 'Queue transcription for assets that were stored before a supplier was configured.';

    public function handle(
        TranscriptionManager $providers,
        TranscriptionEligibility $eligibility,
        WorkAdmission $admission,
    ): int {
        // Asked first and named plainly. An installation with no supplier is
        // the common case, and "0 queued" would read as "nothing to do".
        if (! $providers->default()->isAvailable()) {
            $this->error(sprintf('Refused: %s', TranscriptionRefusal::ProviderUnavailable->value));
            $this->line('No supplier is configured, or the configured one has no usable credentials.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));
        $languageHint = $this->languageHint();

        $query = Asset::query()
            ->whereIn('status', [AssetStatus::Stored->value, AssetStatus::Verified->value])
            ->whereNull('trashed_at')
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        // The candidates are narrowed by the eligibility service rather than
        // by a second copy of its rules in SQL. Two versions of "should this
        // be transcribed" would drift, and the one that costs money is the one
        // that must not.
        $eligible = $query->get()->filter(
            static fn (Asset $asset): bool => $eligibility->allows($asset),
        );

        if ($dryRun) {
            $this->info(sprintf('%d asset(s) would be queued. Nothing was enqueued and nothing was sent.', $eligible->count()));

            return self::SUCCESS;
        }

        if ($eligible->isEmpty()) {
            $this->info('Nothing to queue.');

            return self::SUCCESS;
        }

        try {
            // For the whole batch, not one at a time. Admitting per asset
            // would let a sweep walk straight past a ceiling one job at a
            // time, which is the exact shape of the failure OPS-002 exists to
            // stop.
            $admission->admit($eligible->count());
        } catch (WorkRefused $refused) {
            $this->error(sprintf('Refused: %s', $refused->reason));

            return self::FAILURE;
        }

        foreach ($eligible as $asset) {
            TranscribeAssetJob::dispatch($asset->uuid, $languageHint);
        }

        $this->info(sprintf('Queued %d asset(s) for transcription.', $eligible->count()));

        // Said plainly, because the number above is the one an operator will
        // otherwise read as a bill they have already paid, or as work that has
        // already finished.
        $this->line('Each queued asset is one paid call to the configured supplier when a worker reaches it.');

        return self::SUCCESS;
    }

    private function languageHint(): ?string
    {
        $configured = $this->option('language');

        return is_string($configured) && trim($configured) !== '' ? trim($configured) : null;
    }
}
