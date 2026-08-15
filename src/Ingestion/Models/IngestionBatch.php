<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use SaniTube\Foundation\Concerns\HasPublicUuid;
use SaniTube\Ingestion\Enums\IngestionBatchStatus;
use SaniTube\Ingestion\Enums\IngestionItemStatus;
use SaniTube\Ingestion\Enums\IngestionSource;

/**
 * One import operation.
 *
 * Carries no counters. Everything a progress display needs is a query against
 * `ingestion_items`, and a stored count that drifts would make a batch look
 * finished while items were still in flight.
 *
 * @property int $id
 * @property string $uuid
 * @property IngestionSource $source
 * @property IngestionBatchStatus $status
 * @property int|null $created_by
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class IngestionBatch extends Model
{
    use HasPublicUuid;

    protected $table = 'ingestion_batches';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => IngestionSource::class,
            'status' => IngestionBatchStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<IngestionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(IngestionItem::class, 'batch_id');
    }

    /**
     * Counts per item status, derived rather than stored.
     *
     * @return array<string, int>
     */
    public function itemCounts(): array
    {
        /** @var array<string, int> $counts */
        $counts = $this->items()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();

        $totals = [];

        foreach (IngestionItemStatus::cases() as $status) {
            $totals[$status->value] = $counts[$status->value] ?? 0;
        }

        return $totals;
    }

    public function total(): int
    {
        return $this->items()->count();
    }

    /**
     * Whether anything is still in flight.
     */
    public function hasWorkRemaining(): bool
    {
        return $this->items()->whereNotNull('active_marker')->exists();
    }
}
