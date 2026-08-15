<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use SaniTube\Assets\Models\Asset;
use SaniTube\Catalog\Models\Track;
use SaniTube\Foundation\Concerns\HasPublicUuid;
use SaniTube\Ingestion\Enums\IngestionFailureCode;
use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Enums\TrackCandidateStatus;

/**
 * A proposal that a Track could exist.
 *
 * Everything on it that looks like catalogue data is prefixed `suggested_` or
 * `matched_`, and that naming is load-bearing. A candidate holds guesses — a
 * title read off a filename, an asset that hashes the same as one already
 * held — and a guess that is stored under an authoritative-sounding name is a
 * guess that will eventually be read as fact.
 *
 * @property int $id
 * @property string $uuid
 * @property IngestionSource $source
 * @property int $asset_id
 * @property string $original_filename
 * @property string|null $suggested_title
 * @property int|null $matched_track_id
 * @property int|null $matched_asset_id
 * @property TrackCandidateStatus $status
 * @property array<string, mixed>|null $metadata
 * @property IngestionFailureCode|null $failure_code
 * @property string|null $failure_message
 * @property int|null $promoted_track_id
 * @property Carbon|null $reviewed_at
 * @property int|null $reviewed_by
 * @property string|null $review_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class TrackCandidate extends Model
{
    use HasPublicUuid;

    protected $table = 'track_candidates';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => IngestionSource::class,
            'status' => TrackCandidateStatus::class,
            'failure_code' => IngestionFailureCode::class,
            'metadata' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function matchedAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'matched_asset_id');
    }

    /**
     * @return BelongsTo<Track, $this>
     */
    public function matchedTrack(): BelongsTo
    {
        return $this->belongsTo(Track::class, 'matched_track_id');
    }

    /**
     * @return BelongsTo<Track, $this>
     */
    public function promotedTrack(): BelongsTo
    {
        return $this->belongsTo(Track::class, 'promoted_track_id');
    }

    public function isPromoted(): bool
    {
        return $this->status === TrackCandidateStatus::Promoted;
    }
}
