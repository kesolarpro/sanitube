<?php

declare(strict_types=1);

namespace SaniTube\Artwork\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use SaniTube\Artwork\ArtworkMeasurement;
use SaniTube\Assets\Models\Asset;
use SaniTube\Foundation\Concerns\HasPublicUuid;

/**
 * One measurement of one artwork asset.
 *
 * @property int $id
 * @property string $uuid
 * @property int $asset_id
 * @property string $measurer
 * @property string $measurer_version
 * @property bool $succeeded
 * @property int|null $width_px
 * @property int|null $height_px
 * @property string|null $mime_type
 * @property int|null $bytes
 * @property int|null $channels
 * @property int|null $bits
 * @property Carbon|null $measured_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ArtworkAnalysis extends Model
{
    use HasPublicUuid;

    protected $table = 'artwork_analyses';

    protected $guarded = [];

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    /**
     * The stored row as the value object the validator reads.
     *
     * Null when the measurement failed, so a caller cannot accidentally treat
     * "nobody could read this file" as a set of dimensions.
     */
    public function measurement(): ?ArtworkMeasurement
    {
        if (! $this->succeeded || $this->width_px === null || $this->height_px === null) {
            return null;
        }

        return new ArtworkMeasurement(
            widthPx: $this->width_px,
            heightPx: $this->height_px,
            mimeType: $this->mime_type ?? '',
            bytes: $this->bytes ?? 0,
            channels: $this->channels,
            bits: $this->bits,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'succeeded' => 'boolean',
            'measured_at' => 'datetime',
        ];
    }
}
