<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use SaniTube\Catalog\Enums\TrackStatus;
use SaniTube\Distribution\Enums\DistributionDeliveryStatus;
use SaniTube\Foundation\Database\SchemaPresence;
use SaniTube\Ingestion\Enums\IngestionBatchStatus;
use SaniTube\Ingestion\Enums\TrackCandidateStatus;
use SaniTube\MusicGeneration\Enums\MusicGenerationStatus;
use SaniTube\Observability\Health\OperationalHealthStore;
use SaniTube\Releases\Enums\ReleaseStatus;

/**
 * Everything the dashboard shows, read once and read cheaply.
 *
 * Two rules shape this class, and both come from what a dashboard is *for*.
 *
 * **Unknown is not zero.** An operator who sees "0 failed jobs" concludes the
 * queue is healthy. If the truth is that the table does not exist or the probe
 * threw, that conclusion is wrong and was caused by this screen. Every figure
 * here is therefore `int` when it was counted and `null` when it could not be,
 * and the interface renders the two differently — a number against an em dash.
 *
 * **One query per aggregate, never one per row.** Counts by lifecycle state
 * are a single grouped count each, expanded against the enum afterwards in
 * PHP, so a status with no rows still appears as a real zero rather than
 * vanishing. The whole snapshot is a fixed, small number of queries regardless
 * of how large the catalogue grows — which matters, because the catalogue this
 * is being built for is about nine hundred tracks and will not stop there.
 *
 * Nothing here loads a model. The dashboard needs counts, and hydrating
 * Eloquent objects to count them is how a dashboard becomes the slowest page
 * in an application.
 */
final readonly class DashboardQuery
{
    public function __construct(
        private OperationalHealthStore $health,
        private SchemaPresence $tables,
        private StorageUsageQuery $storageUsage,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'catalogue' => $this->catalogue(),
            'tracks_by_status' => $this->countByStatus('tracks', 'status', TrackStatus::cases()),
            'releases_by_status' => $this->countByStatus('releases', 'status', ReleaseStatus::cases()),
            'ingestion' => $this->ingestion(),
            'media' => $this->media(),
            'generation' => [
                'by_status' => $this->countByStatus('music_generations', 'status', MusicGenerationStatus::cases()),
            ],
            'distribution' => [
                'deliveries_by_status' => $this->countByStatus(
                    'distribution_deliveries',
                    'status',
                    DistributionDeliveryStatus::cases(),
                ),
            ],
            'jobs' => $this->jobs(),
            'operational' => $this->operational(),
            // STO-005. The platform recorded `byte_size` on every asset from
            // the first upload and asked the question nowhere. An asset count
            // says nothing about it: a thousand previews and a thousand
            // masters are the same number and three orders of magnitude apart.
            'storage_usage' => $this->storageUsage->overview(),
        ];
    }

    /**
     * @return array<string, int|null>
     */
    private function catalogue(): array
    {
        return [
            'tracks' => $this->count('tracks'),
            'releases' => $this->count('releases'),
            'artists' => $this->count('artists'),
            'compositions' => $this->count('compositions'),
            'contributors' => $this->count('contributors'),
            'assets' => $this->count('assets'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ingestion(): array
    {
        return [
            'batches_by_status' => $this->countByStatus(
                'ingestion_batches',
                'status',
                IngestionBatchStatus::cases(),
            ),
            'candidates_by_status' => $this->countByStatus(
                'track_candidates',
                'status',
                TrackCandidateStatus::cases(),
            ),
        ];
    }

    /**
     * @return array<string, int|null>
     */
    private function media(): array
    {
        // `succeeded` is a boolean column rather than a lifecycle enum, so this
        // is two counts rather than a grouped one.
        return [
            'analyses' => $this->count('audio_analyses'),
            'succeeded' => $this->count('audio_analyses', fn (BuilderContract $query) => $query->where('succeeded', true)),
            'failed' => $this->count('audio_analyses', fn (BuilderContract $query) => $query->where('succeeded', false)),
        ];
    }

    /**
     * Queue depth and failures.
     *
     * Both are null when the queue tables are absent — a perfectly ordinary
     * state on a fresh install, and one that must not be shown as "no failures".
     *
     * @return array<string, int|null>
     */
    private function jobs(): array
    {
        return [
            'pending' => $this->count('jobs'),
            'failed' => $this->count('failed_jobs'),
        ];
    }

    /**
     * External state, read from the last scheduled probe — never probed here.
     *
     * This is the boundary the whole class exists to hold. `GET /` must not
     * write a storage probe object, must not call a provider over the network,
     * and must not shell out to a capability detector: a page load that does
     * real I/O against five external systems is slow when they are healthy and
     * unusable when they are not, which is exactly when somebody opens it.
     *
     * Three states, and the third is the one that matters:
     *
     *   - UNKNOWN — nothing stored. A fresh install, or the scheduler has never
     *     run. Every value is null.
     *   - STALE — stored, but older than the configured threshold. **Every
     *     availability value is forced back to null.** A snapshot from three
     *     hours ago is not evidence about now, and letting a stale `healthy`
     *     through is how a dashboard reports green through an outage. The
     *     timestamp is kept so the reader can see how old it is.
     *   - FRESH — stored and recent. Values pass through as probed.
     *
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
                'generation_provider' => ['name' => null, 'available' => null],
                'distributors' => [],
                'capabilities' => ['healthy' => null, 'items' => []],
            ];
        }

        $stale = $this->health->isStale($health);

        return [
            'state' => $stale ? 'STALE' : 'FRESH',
            'checked_at' => $health->checkedAt->format(DATE_ATOM),
            'stale_after_seconds' => $this->health->staleAfterSeconds(),
            'storage' => $stale ? $this->withoutVerdict($health->storage, ['healthy']) : $health->storage,
            'ai' => $stale ? $this->withoutVerdict($health->ai, ['available']) : $health->ai,
            'generation_provider' => $stale ? $this->withoutVerdict($health->generation, ['available']) : $health->generation,
            'distributors' => $stale
                ? array_map(fn (array $one): array => $this->withoutVerdict($one, ['available']), $health->distributors)
                : $health->distributors,
            'capabilities' => $stale
                ? ['healthy' => null, 'items' => $health->capabilities['items'] ?? []]
                : $health->capabilities,
        ];
    }

    /**
     * The same record with its verdicts blanked to "unknown".
     *
     * Names and probe details are still worth showing when a snapshot is stale
     * — they say *what* was checked. The verdicts are not, because they are
     * assertions about the present.
     *
     * @param  array<string, mixed>  $record
     * @param  list<string>  $verdicts
     * @return array<string, mixed>
     */
    private function withoutVerdict(array $record, array $verdicts): array
    {
        foreach ($verdicts as $key) {
            $record[$key] = null;
        }

        return $record;
    }

    /**
     * One grouped count, expanded against the enum.
     *
     * Expanding in PHP rather than trusting the result set is what makes a
     * state with no rows render as `0` instead of disappearing from the chart —
     * "no failed deliveries" and "the failed row is missing" look identical
     * otherwise.
     *
     * @param  list<\BackedEnum>  $cases
     * @return array<string, int>|null
     */
    private function countByStatus(string $table, string $column, array $cases): ?array
    {
        if (! $this->tableExists($table)) {
            return null;
        }

        try {
            /** @var array<string, int> $counted */
            $counted = DB::table($table)
                ->selectRaw($column.' as status, count(*) as aggregate')
                ->groupBy($column)
                ->pluck('aggregate', 'status')
                ->all();
        } catch (QueryException) {
            return null;
        }

        $totals = [];

        foreach ($cases as $case) {
            $key = (string) $case->value;
            $totals[$key] = (int) ($counted[$key] ?? 0);
        }

        return $totals;
    }

    /**
     * @param  (callable(BuilderContract): BuilderContract)|null  $constrain
     */
    private function count(string $table, ?callable $constrain = null): ?int
    {
        if (! $this->tableExists($table)) {
            return null;
        }

        try {
            $query = DB::table($table);

            return $constrain === null ? $query->count() : $constrain($query)->count();
        } catch (QueryException) {
            return null;
        }
    }

    private function tableExists(string $table): bool
    {
        return $this->tables->has($table);
    }
}
