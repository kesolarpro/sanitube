<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use SaniTube\Assets\Models\Asset;
use SaniTube\Foundation\Concerns\HasPublicUuid;
use SaniTube\Ingestion\Models\TrackCandidate;

/**
 * One audio variant a generation produced.
 *
 * @property int $id
 * @property string $uuid
 * @property int $generation_id
 * @property string $provider_result_id
 * @property string|null $source_reference
 * @property string|null $title
 * @property int|null $duration_ms
 * @property bool $selected
 * @property int|null $asset_id
 * @property int|null $candidate_id
 * @property array<string, mixed>|null $raw
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MusicGenerationResult extends Model
{
    use HasPublicUuid;

    protected $table = 'music_generation_results';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'selected' => 'boolean',
            'raw' => 'array',
        ];
    }

    /**
     * Whether there is anything to import.
     *
     * A completed generation with no audio is a provider fault rather than an
     * empty track, and the distinction has to survive as far as selection.
     */
    public function hasAudio(): bool
    {
        return $this->source_reference !== null && trim($this->source_reference) !== '';
    }

    /**
     * @return BelongsTo<MusicGeneration, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(MusicGeneration::class, 'generation_id');
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
}
