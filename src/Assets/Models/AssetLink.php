<?php

declare(strict_types=1);

namespace SaniTube\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use SaniTube\Assets\Enums\AssetLinkRole;

/**
 * A secondary attachment between an asset and something in the catalogue.
 *
 * Masters and covers are not expressible here — {@see AssetLinkRole} has no
 * case for them — because each is a single-valued fact that lives in a foreign
 * key on its owner. Allowing a second way to state the same thing is how two
 * answers to "which file is the master?" come to exist.
 *
 * @property int $id
 * @property int $asset_id
 * @property string $linkable_type
 * @property int $linkable_id
 * @property AssetLinkRole $role
 * @property int $position
 */
final class AssetLink extends Model
{
    protected $table = 'asset_links';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => AssetLinkRole::class,
        ];
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }
}
