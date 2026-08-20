<?php

declare(strict_types=1);

namespace SaniTube\Media\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use SaniTube\Assets\Models\Asset;
use SaniTube\Foundation\Concerns\HasPublicUuid;

/**
 * One acoustic fingerprint of one asset.
 *
 * Evidence, not a verdict. Nothing here says two assets are the same
 * recording; it says what each one sounds like and leaves the comparison — and
 * the threshold, and the human review — to the code that reads it.
 *
 * @property int $id
 * @property string $uuid
 * @property int $asset_id
 * @property string $algorithm
 * @property string $tool
 * @property string $tool_version
 * @property string $fingerprint
 * @property string $fingerprint_hash
 * @property int $duration_seconds
 * @property Carbon $computed_at
 */
final class AudioFingerprint extends Model
{
    use HasPublicUuid;

    protected $table = 'audio_fingerprints';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['computed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
