<?php

declare(strict_types=1);

namespace SaniTube\Media\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use SaniTube\Assets\Models\Asset;
use SaniTube\Foundation\Concerns\HasPublicUuid;

/**
 * What one analyser, at one version, concluded about one asset.
 *
 * Never overwritten by a newer version. A recording analysed in 2026 and again
 * in 2028 has two rows, and the older one stays readable — otherwise "why did
 * this track's duration change?" has no answer.
 *
 * @property int $id
 * @property string $uuid
 * @property int $asset_id
 * @property string $analyzer
 * @property string $analyzer_version
 * @property bool $succeeded
 * @property int|null $duration_ms
 * @property string|null $codec
 * @property string|null $container
 * @property int|null $sample_rate
 * @property int|null $bit_depth
 * @property int|null $channels
 * @property int|null $bitrate
 * @property string|null $loudness_lufs
 * @property string|null $peak_dbfs
 * @property array<string, mixed>|null $raw
 * @property string|null $failure_message
 * @property Carbon|null $analyzed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class AudioAnalysis extends Model
{
    use HasPublicUuid;

    protected $table = 'audio_analyses';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'succeeded' => 'boolean',
            'raw' => 'array',
            'analyzed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
