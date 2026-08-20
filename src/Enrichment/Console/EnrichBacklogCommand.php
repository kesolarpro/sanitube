<?php

declare(strict_types=1);

namespace SaniTube\Enrichment\Console;

use Illuminate\Console\Command;
use SaniTube\AI\Services\AiCircuitBreaker;
use SaniTube\AI\Services\AiSpendGuard;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Enrichment\Enums\EnrichmentRefusal;
use SaniTube\Enrichment\Jobs\SuggestMetadataJob;
use SaniTube\Enrichment\Services\EnrichmentEligibility;
use SaniTube\Operations\Exceptions\WorkRefused;
use SaniTube\Operations\Services\WorkAdmission;

/**
 * Suggests metadata for a library transcribed before enrichment existed.
 *
 * New transcripts are offered to a model as they arrive, if this installation
 * turned that on. This is for everything already there — which, on an
 * installation that has been running a while, is all of it. Without it,
 * `SuggestWhenTranscribed` only ever fires for the future and a catalogue
 * transcribed last month never gets a suggestion at all.
 *
 * **This one costs money**, so it is built like its transcription counterpart
 * rather than like a convenience: `--limit` defaults low rather than to
 * everything, a dry run reports exactly what would be sent, and the admission
 * gate is asked for the *whole batch* — because the failure being guarded
 * against is four thousand jobs landing on a queue a cron worker drains once a
 * minute.
 *
 * **It also asks the spend guard and the circuit breaker before queueing, which
 * the transcription command has no equivalent of.** ENR-003's guard exists for
 * precisely this scenario, and its docblock says so: a backfill over four
 * thousand masters is bounded by the queue ceiling in how many sit there at
 * once, and bounded by nothing at all in how many reach a vendor over the
 * following week. Queueing into an exhausted ceiling would produce thousands of
 * jobs that each wake a worker, consult the guard and refuse — work that costs
 * nothing at the vendor and a great deal on a shared host.
 *
 * Nothing is enriched inline. The command queues, and says how many.
 */
final class EnrichBacklogCommand extends Command
{
    protected $signature = 'sanitube:enrichment:backlog
        {--limit=100 : how many assets to queue, 0 for all}
        {--dry-run : report what would be queued and enqueue nothing}';

    protected $description = 'Queue metadata suggestions for assets transcribed before enrichment existed.';

    public function handle(
        EnrichmentEligibility $eligibility,
        WorkAdmission $admission,
        AiSpendGuard $ceiling,
        AiCircuitBreaker $breaker,
    ): int {
        $dryRun = (bool) $this->option('dry-run');

        // Asked first and named plainly. An installation with no model is the
        // common case, and "0 queued" would read as "nothing to do".
        if (! $eligibility->hasModel()) {
            $this->error(sprintf('Refused: %s', EnrichmentRefusal::ModelUnavailable->value));
            $this->line('No AI provider is configured, or the configured one has no usable credentials.');

            return self::FAILURE;
        }

        $exhausted = $ceiling->exhaustedWindow();

        if ($exhausted !== null && ! $dryRun) {
            $this->error(sprintf('Refused: the %s call ceiling for this installation has been reached.', $exhausted));
            $this->line('Nothing was queued, so nothing was spent. The window is rolling and makes room on its own.');

            return self::FAILURE;
        }

        $limit = max(0, (int) $this->option('limit'));

        $query = Asset::query()
            ->whereIn('status', [AssetStatus::Stored->value, AssetStatus::Verified->value])
            ->whereNull('trashed_at')
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        // Eligibility is decided by the service, not by a second copy of its
        // rules in SQL: two versions of "should this be enriched" would drift,
        // and the one that costs money is the one that must not.
        //
        // The trashed filter above is a *pre*-filter rather than a second
        // opinion, and it earns its place: `--limit` bounds the rows read, so
        // without it a trashed asset ordered first consumes the only slot and
        // the eligible one behind it is never reached. The sweep would report
        // success having queued nothing, for ever.
        $eligible = $query->get()->filter(
            static fn (Asset $asset): bool => $eligibility->allows($asset),
        );

        if ($dryRun) {
            $this->info(sprintf(
                '%d asset(s) would be queued. Nothing was enqueued and nothing was sent.',
                $eligible->count(),
            ));

            if ($exhausted !== null) {
                // Reported rather than refused, because a dry run that stayed
                // silent about this would tell an operator to expect work that
                // a real run would decline.
                $this->warn(sprintf('The %s call ceiling is currently exhausted, so a real run would refuse.', $exhausted));
            }

            return self::SUCCESS;
        }

        if ($eligible->isEmpty()) {
            $this->info('Nothing to queue.');

            return self::SUCCESS;
        }

        try {
            // For the whole batch, not one at a time. Admitting per asset would
            // let a sweep walk straight past a ceiling one job at a time.
            $admission->admit($eligible->count());
        } catch (WorkRefused $refused) {
            $this->error(sprintf('Refused: %s', $refused->reason));

            return self::FAILURE;
        }

        foreach ($eligible as $asset) {
            SuggestMetadataJob::dispatch($asset->uuid);
        }

        $this->info(sprintf('Queued %d asset(s) for metadata suggestions.', $eligible->count()));

        if ($breaker->isOpenFor($this->providerName())) {
            // Not a refusal: the cooldown may well pass before a worker reaches
            // the first job. But an operator watching a queue drain into
            // nothing deserves to know why.
            $this->warn('The configured provider is in cooldown after repeated failures; early jobs may refuse.');
        }

        // Said plainly, because the number above is the one an operator will
        // otherwise read as a bill already paid, or as work already finished.
        $this->line('Each queued asset is one paid call to the configured model when a worker reaches it.');

        return self::SUCCESS;
    }

    private function providerName(): string
    {
        $name = config('ai.default');

        return is_string($name) ? $name : '';
    }
}
