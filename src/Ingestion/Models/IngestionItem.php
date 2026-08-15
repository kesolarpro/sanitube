<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use SaniTube\Assets\Models\Asset;
use SaniTube\Foundation\Concerns\HasPublicUuid;
use SaniTube\Ingestion\Enums\IngestionFailureCode;
use SaniTube\Ingestion\Enums\IngestionItemStatus;

/**
 * One source inside a batch.
 *
 * `active_marker` is not decoration: paired with `ingestion_key` in a unique
 * index, it is what stops the same source being processed twice at once. The
 * observer keeps it consistent with `status` so no caller has to remember.
 *
 * @property int $id
 * @property string $uuid
 * @property int $batch_id
 * @property string $ingestion_key
 * @property string $source_reference
 * @property string $original_filename
 * @property IngestionItemStatus $status
 * @property int|null $active_marker
 * @property int|null $asset_id
 * @property int|null $candidate_id
 * @property IngestionFailureCode|null $failure_code
 * @property string|null $failure_message
 * @property int $attempt_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class IngestionItem extends Model
{
    use HasPublicUuid;

    /** The value `active_marker` carries while an item is in flight. */
    public const ACTIVE = 1;

    protected $table = 'ingestion_items';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IngestionItemStatus::class,
            'failure_code' => IngestionFailureCode::class,
            'attempt_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<IngestionBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(IngestionBatch::class, 'batch_id');
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    /**
     * @return BelongsTo<TrackCandidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(TrackCandidate::class, 'candidate_id');
    }

    public function isInFlight(): bool
    {
        return ! $this->status->isTerminal();
    }
}
