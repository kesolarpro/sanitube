<?php

declare(strict_types=1);

namespace SaniTube\Media\Console;

use Illuminate\Console\Command;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Media\Jobs\FingerprintAssetJob;
use SaniTube\Media\Services\FingerprintAsset;
use SaniTube\Operations\Exceptions\WorkRefused;
use SaniTube\Operations\Services\WorkAdmission;

/**
 * Fingerprints a library that was stored before this host could fingerprint.
 *
 * **The gap this closes is silent, which is what makes it serious.** A
 * fingerprint is taken when a candidate appears, and only then. An installation
 * that imported four thousand masters on a shared account with no Chromaprint
 * got no fingerprints — correctly: an absent tool is a supported configuration,
 * not a failure. When that installation later gains the capability — a static
 * `fpcalc` uploaded, or a remote worker attached, which WRK-002 made an ordinary
 * thing to do — **nothing goes back over what is already there.**
 *
 * The consequence is not an error anywhere. Acoustic duplicate detection simply
 * covers none of the catalogue, for ever, and every screen reports exactly what
 * it did before. `sanitube:doctor` now says so, and this is what it points at.
 *
 * The shape is `sanitube:transcription:backlog`'s, and deliberately so — the
 * two answer the same question about different capabilities. Two differences,
 * both from what is being spent:
 *
 *   - **An absent tool is not a failure here.** Transcription refuses, because
 *     a supplier is a deliberate configuration and its absence is a mistake
 *     worth exiting non-zero over. Chromaprint is a property of the host, and
 *     a nightly cron that mails an error every night trains its reader to
 *     ignore it.
 *   - **The default limit is higher.** Nothing here calls a paid API. What it
 *     spends is CPU, or a worker's time, and the ceiling that matters is the
 *     queue's — which is why admission is asked for the whole sweep rather
 *     than per asset.
 *
 * Nothing is fingerprinted inline. The command queues the same job the import
 * path queues, so an asset fingerprinted by a sweep is compared for duplicates
 * by exactly the same route as one fingerprinted on arrival.
 */
final class FingerprintBacklogCommand extends Command
{
    protected $signature = 'sanitube:media:fingerprint
        {--limit=500 : how many assets to queue, 0 for all}
        {--dry-run : report what would be queued and enqueue nothing}
        {--json : output the outcome as JSON}';

    protected $description = 'Queue acoustic fingerprinting for assets stored before this host could fingerprint.';

    public function handle(FingerprintAsset $fingerprinter, WorkAdmission $admission): int
    {
        if (! $fingerprinter->isAvailable()) {
            $this->warn(
                'This host cannot take an acoustic fingerprint. Install Chromaprint, point '
                    .'SANITUBE_FPCALC_PATH at an uploaded static build, or attach a worker that '
                    .'offers the capability. Nothing was changed.',
            );

            // Not a failure exit code. An installation without Chromaprint is
            // a supported configuration — duplicates are found by checksum —
            // and a scheduled command that fails nightly on a correct install
            // is one whose output stops being read.
            return self::SUCCESS;
        }

        $limit = max(0, (int) $this->option('limit'));

        $query = Asset::query()
            // Settled bytes only. An upload still in flight has nothing stable
            // to measure, and measuring it would race the write.
            ->whereIn('status', [AssetStatus::Stored->value, AssetStatus::Verified->value])
            ->whereNull('trashed_at')
            ->orderBy('id');

        // Narrowed by the service rather than by a second copy of its rule in
        // SQL. "Has this been fingerprinted by the tool we have today" is one
        // question with one answer, and a version bump has to move both.
        $outstanding = $query->get()->filter(
            static fn (Asset $asset): bool => $fingerprinter->needs($asset),
        );

        $queueing = $limit > 0 ? $outstanding->take($limit) : $outstanding;
        $remaining = $outstanding->count() - $queueing->count();

        if ((bool) $this->option('dry-run')) {
            return $this->report($outstanding->count(), 0, $outstanding->count(), 'dry run');
        }

        if ($queueing->isEmpty()) {
            return $this->report(0, 0, 0, 'nothing outstanding');
        }

        try {
            // For the whole sweep, not one at a time. Admitting per asset
            // would let it walk straight past a ceiling four thousand jobs at
            // a time, which is the exact shape OPS-002 exists to stop.
            $admission->admit($queueing->count());
        } catch (WorkRefused $refused) {
            $this->error(sprintf('Refused: %s', $refused->reason));

            return self::FAILURE;
        }

        foreach ($queueing as $asset) {
            FingerprintAssetJob::dispatch($asset->uuid);
        }

        return $this->report($outstanding->count(), $queueing->count(), $remaining, 'queued');
    }

    private function report(int $outstanding, int $queued, int $remaining, string $outcome): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode([
                'outstanding' => $outstanding,
                'queued' => $queued,
                'remaining' => $remaining,
                'outcome' => $outcome,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($outcome === 'dry run') {
            $this->info(sprintf('%d asset(s) would be queued. Nothing was enqueued.', $outstanding));

            return self::SUCCESS;
        }

        if ($queued === 0) {
            $this->info('Every stored master has been fingerprinted by the tool this host has.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Queued %d of %d outstanding asset(s) for fingerprinting.', $queued, $outstanding));

        if ($remaining > 0) {
            // Said out loud, because the limit is the whole reason a sweep can
            // look finished while it is not.
            $this->line(sprintf('  %d still outstanding. Run this again once the queue has drained.', $remaining));
        }

        return self::SUCCESS;
    }
}
