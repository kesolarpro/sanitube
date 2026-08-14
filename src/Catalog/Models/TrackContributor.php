<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use SaniTube\Catalog\Enums\TrackContributorRole;
use SaniTube\Contributors\Models\Contributor;

/**
 * Who did what on a recording — performers, producers, engineers.
 *
 * @property int $id
 * @property int $track_id
 * @property int $contributor_id
 * @property TrackContributorRole $role
 * @property int $position
 */
final class TrackContributor extends Pivot
{
    protected $table = 'track_contributor';

    public $incrementing = true;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['role' => TrackContributorRole::class];
    }

    /**
     * @return BelongsTo<Track, $this>
     */
    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    /**
     * @return BelongsTo<Contributor, $this>
     */
    public function contributor(): BelongsTo
    {
        return $this->belongsTo(Contributor::class);
    }
}
