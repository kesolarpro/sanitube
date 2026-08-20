<?php

declare(strict_types=1);

namespace SaniTube\Artwork\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use SaniTube\Artwork\Enums\ArtworkGenerationStatus;
use SaniTube\Artwork\Enums\ArtworkRefusal;
use SaniTube\Assets\Models\Asset;
use SaniTube\Foundation\Concerns\HasPublicUuid;
use SaniTube\Releases\Models\Release;

/**
 * One request to make a cover.
 *
 * **The row is the ledger**, and `submitted_at` is the entry that counts: it is
 * written exactly when a request left this server for a provider. A request
 * refused before that — no provider, requirements unreachable, a ceiling, a
 * claim lost to another worker — cost nothing and does not count against the
 * ceiling that produced it.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $release_id
 * @property string $provider
 * @property string|null $provider_reference
 * @property ArtworkGenerationStatus $status
 * @property string $prompt
 * @property string|null $model
 * @property string|null $quality
 * @property int|null $requested_width
 * @property int|null $requested_height
 * @property int|null $asset_id
 * @property ArtworkRefusal|null $failure_code
 * @property string|null $estimated_cost_hint
 * @property int $attempts
 * @property Carbon|null $claimed_at
 * @property Carbon|null $submitted_at
 * @property Carbon|null $completed_at
 * @property int|null $requested_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ArtworkGeneration extends Model
{
    use HasPublicUuid;

    protected $table = 'artwork_generations';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ArtworkGenerationStatus::class,
            'failure_code' => ArtworkRefusal::class,
            'attempts' => 'integer',
            'requested_width' => 'integer',
            'requested_height' => 'integer',
            'claimed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Release, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'release_id');
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    /**
     * Whether this request ever reached a provider.
     *
     * The one question the spend guard asks, named here so no caller has to
     * remember that `submitted_at` is what "cost something" means.
     */
    public function wasSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }
}
