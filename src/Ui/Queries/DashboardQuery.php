<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SaniTube\AI\AiManager;
use SaniTube\Catalog\Enums\TrackStatus;
use SaniTube\Distribution\DistributorManager;
use SaniTube\Distribution\Enums\DistributionDeliveryStatus;
use SaniTube\Ingestion\Enums\IngestionBatchStatus;
use SaniTube\Ingestion\Enums\TrackCandidateStatus;
use SaniTube\MusicGeneration\Enums\MusicGenerationStatus;
use SaniTube\MusicGeneration\MusicGenerationManager;
use SaniTube\Observability\Capabilities\CapabilityRegistry;
use SaniTube\Releases\Enums\ReleaseStatus;
use SaniTube\Storage\StorageManager;
use Throwable;

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
        private CapabilityRegistry $capabilities,
        private AiManager $ai,
        private MusicGenerationManager $generation,
        private DistributorManager $distributors,
        private StorageManager $storage,
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
            'generation' => $this->generation(),
            'distribution' => $this->distribution(),
            'jobs' => $this->jobs(),
            'storage' => $this->storage(),
            'capabilities' => $this->capabilityReport(),
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
     * @return array<string, mixed>
     */
    private function generation(): array
    {
        return [
            'by_status' => $this->countByStatus('music_generations', 'status', MusicGenerationStatus::cases()),
            'provider' => $this->providerState(
                $this->generation->defaultName(),
                fn (): bool => $this->generation->isAvailable(),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function distribution(): array
    {
        $distributors = [];

        foreach ($this->distributors->names() as $name) {
            $distributors[] = $this->providerState(
                $name,
                fn (): bool => $this->distributors->distributor($name)->isAvailable(),
            );
        }

        return [
            'deliveries_by_status' => $this->countByStatus(
                'distribution_deliveries',
                'status',
                DistributionDeliveryStatus::cases(),
            ),
            'distributors' => $distributors,
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
     * @return array<string, mixed>
     */
    private function storage(): array
    {
        $name = $this->storage->defaultName();

        try {
            $health = $this->storage->provider($name)->healthCheck();

            return [
                'provider' => $health->provider,
                'healthy' => $health->healthy,
                'checks' => $health->checks,
                'temporary_urls' => $health->temporaryUrls,
                // Already redacted at the StorageHealth constructor, which is
                // the single place every failing report passes through.
                'detail' => $health->detail,
            ];
        } catch (Throwable) {
            // A provider that cannot even be probed is unknown, not unhealthy:
            // the two call for different responses from an operator.
            return [
                'provider' => $name,
                'healthy' => null,
                'checks' => [],
                'temporary_urls' => null,
                'detail' => null,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function capabilityReport(): array
    {
        $report = $this->capabilities->report();

        return [
            'healthy' => $report->isHealthy(),
            'items' => $report->toArray(),
            'ai' => $this->providerState(
                $this->ai->defaultName(),
                fn (): bool => $this->ai->isAvailable(),
            ),
        ];
    }

    /**
     * A provider's name and whether it can currently be used.
     *
     * `available` is nullable on purpose. A provider that throws while being
     * asked is not the same as one that answered "no", and reporting a network
     * blip as a deliberate refusal sends an operator looking in the wrong place.
     *
     * @param  callable(): bool  $probe
     * @return array<string, mixed>
     */
    private function providerState(string $name, callable $probe): array
    {
        try {
            return ['name' => $name, 'available' => $probe()];
        } catch (Throwable) {
            return ['name' => $name, 'available' => null];
        }
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
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }
}
