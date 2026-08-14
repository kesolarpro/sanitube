<?php

declare(strict_types=1);

namespace SaniTube\Assets\Models;

use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Observers\AssetObserver;
use SaniTube\Foundation\Concerns\HasPublicUuid;

/**
 * A file the platform is responsible for.
 *
 * An Asset row is the record that bytes exist somewhere. It is never soft
 * deleted: hiding the row would not unwrite the file, it would only make the
 * file unaccounted for.
 *
 * Two lineages are tracked and they mean different things. `parent_asset_id`
 * records derivation — this preview was made from that master. `duplicate_of_asset_id`
 * records recognition — these bytes are the same as an asset already held.
 * Neither may point at itself or close a cycle; {@see AssetObserver}
 * enforces that.
 *
 * @property int $id
 * @property string $uuid
 * @property AssetKind $kind
 * @property int|null $parent_asset_id
 * @property int|null $duplicate_of_asset_id
 * @property string $disk
 * @property string $path
 * @property string $original_filename
 * @property string $mime_type
 * @property int $byte_size
 * @property string $sha256
 * @property int|null $duration_ms
 * @property int|null $sample_rate
 * @property int|null $bit_depth
 * @property int|null $channels
 * @property int|null $width
 * @property int|null $height
 * @property AssetStatus $status
 * @property Carbon|null $stored_at
 * @property Carbon|null $verified_at
 */
final class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $table = 'assets';

    protected $guarded = [];

    /**
     * Fields frozen once the asset reaches a stored state (I1). Editing any of
     * them would mean the row no longer describes the bytes it claims to.
     */
    public const IMMUTABLE_ONCE_STORED = ['disk', 'path', 'sha256', 'byte_size', 'kind'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AssetKind::class,
            'status' => AssetStatus::class,
            'stored_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    protected static function newFactory(): AssetFactory
    {
        return AssetFactory::new();
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_asset_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function derivatives(): HasMany
    {
        return $this->hasMany(self::class, 'parent_asset_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_asset_id');
    }

    /**
     * @return HasMany<AssetLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(AssetLink::class);
    }

    public function isImmutable(): bool
    {
        return $this->status->isImmutable();
    }

    public function isVerifiedMaster(): bool
    {
        return $this->kind === AssetKind::AudioMaster && $this->status === AssetStatus::Verified;
    }

    public function isVerifiedArtwork(): bool
    {
        return $this->kind === AssetKind::Artwork && $this->status === AssetStatus::Verified;
    }
}
