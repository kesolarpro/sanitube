<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use DateTimeImmutable;
use SaniTube\Deployment\Services\BackupRepository;
use SaniTube\Observability\Health\OperationalHealthStore;
use SaniTube\Observability\SchedulerHeartbeat;
use Throwable;

/**
 * The operational picture, assembled entirely from stored state.
 *
 * **No probe runs here.** Not a storage round trip, not a provider call, not a
 * capability sweep. That boundary was settled by UI-002's review and it applies
 * with more force on this screen than on the dashboard: an operations page is
 * what somebody opens *during* an outage, which is precisely when a page that
 * does real I/O against five external systems will not load.
 *
 * The freshness contract is inherited rather than reimplemented. Three states —
 * UNKNOWN, STALE, FRESH — and **every availability verdict is blanked when the
 * snapshot is stale**. A verdict from three hours ago is not evidence about
 * now, and a health screen reporting green through an outage is worse than one
 * reporting nothing.
 *
 * The scheduler heartbeat is separate from the snapshot on purpose: a
 * scheduler that stopped is exactly why a snapshot would be stale, and reading
 * them from one place would make the cause and the symptom indistinguishable.
 */
final readonly class OperationsQuery
{
    public function __construct(
        private OperationalHealthStore $health,
        private SchedulerHeartbeat $heartbeat,
        private QueueQuery $queue,
        private BackupRepository $backups,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        return [
            'health' => $this->operational(),
            'scheduler' => $this->scheduler(),
            'queue' => $this->queue->summary(),
            'backups' => $this->backups(),
            'runtime' => $this->runtime(),
        ];
    }

    /**
     * When a backup was last taken, and how many there are.
     *
     * On the operations screen because a backup job that silently stopped is
     * the classic disaster: nothing is wrong until the day something is, and
     * by then the newest backup is three months old. A date an operator walks
     * past every week is what makes that visible.
     *
     * Reading the manifests is a directory listing, not a probe — the same
     * boundary the rest of this screen keeps. It never verifies checksums:
     * that is `sanitube:restore --verify`, which is a real read of every byte
     * and has no business running during a page load.
     *
     * `taken_at` of null means **no backup has ever been taken**, and the
     * screen says so rather than showing a dash. Never backed up and backed up
     * a long time ago are different problems.
     *
     * @return array<string, mixed>
     */
    private function backups(): array
    {
        try {
            $complete = $this->backups->complete();
        } catch (Throwable) {
            // A misconfigured destination — inside the web root, say — must
            // report itself rather than take the operations screen down. This
            // is the page somebody opens when things are already wrong.
            return ['readable' => false, 'count' => null, 'taken_at' => null, 'destination_configured' => false];
        }

        return [
            'readable' => true,
            'count' => count($complete),
            'taken_at' => $complete === [] ? null : $complete[0]['manifest']->createdAt,
            'destination_configured' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function operational(): array
    {
        $health = $this->health->current();

        if ($health === null) {
            return [
                'state' => 'UNKNOWN',
                'checked_at' => null,
                'stale_after_seconds' => $this->health->staleAfterSeconds(),
                'storage' => ['provider' => null, 'healthy' => null, 'checks' => [], 'temporary_urls' => null, 'detail' => null],
                'ai' => ['name' => null, 'available' => null],
                'generation' => ['name' => null, 'available' => null],
                'distributors' => [],
                'capabilities' => ['healthy' => null, 'items' => []],
            ];
        }

        $stale = $this->health->isStale($health);

        return [
            'state' => $stale ? 'STALE' : 'FRESH',
            'checked_at' => $health->checkedAt->format(DATE_ATOM),
            'stale_after_seconds' => $this->health->staleAfterSeconds(),
            'storage' => $stale ? $this->blank($health->storage, ['healthy']) : $health->storage,
            'ai' => $stale ? $this->blank($health->ai, ['available']) : $health->ai,
            'generation' => $stale ? $this->blank($health->generation, ['available']) : $health->generation,
            'distributors' => $stale
                ? array_map(fn (array $one): array => $this->blank($one, ['available']), $health->distributors)
                : $health->distributors,
            'capabilities' => $stale
                ? ['healthy' => null, 'items' => $health->capabilities['items'] ?? []]
                : $health->capabilities,
        ];
    }

    /**
     * Whether cron is actually running.
     *
     * A cron entry nobody created is one of the quietest production failures
     * there is: retries, distribution sync and royalty imports simply never
     * happen, and nothing anywhere says so. `last_run_at` of null means no
     * heartbeat has ever been recorded — a fresh install or a missing crontab,
     * and the screen says which is possible rather than guessing between them.
     *
     * @return array<string, mixed>
     */
    private function scheduler(): array
    {
        $lastRun = $this->heartbeat->lastRunAt();

        return [
            'last_run_at' => $lastRun?->format(DATE_ATOM),
            'seconds_since' => $lastRun === null
                ? null
                : max(0, (new DateTimeImmutable)->getTimestamp() - $lastRun->getTimestamp()),
        ];
    }

    /**
     * Facts about this installation that cost nothing to read.
     *
     * Deliberately narrow. The PHP version and the driver names are what an
     * operator needs to answer "am I on the configuration I think I am"; the
     * environment name is what tells them which installation they are looking
     * at. Nothing here reads a credential, a host, a bucket or a path — those
     * are configuration values whose *presence* is the useful fact, and SET-001
     * is where that belongs.
     *
     * @return array<string, mixed>
     */
    private function runtime(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'environment' => (string) config('app.env'),
            'debug' => (bool) config('app.debug'),
            'queue_driver' => (string) config('queue.default'),
            'cache_driver' => (string) config('cache.default'),
            'session_driver' => (string) config('session.driver'),
            'database_driver' => (string) config('database.default'),
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  list<string>  $verdicts
     * @return array<string, mixed>
     */
    private function blank(array $record, array $verdicts): array
    {
        foreach ($verdicts as $key) {
            $record[$key] = null;
        }

        return $record;
    }
}
