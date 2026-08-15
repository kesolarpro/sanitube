<?php

declare(strict_types=1);

namespace SaniTube\Releases\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use SaniTube\Artists\Models\Artist;
use SaniTube\Releases\Enums\ReleaseArtistRole;

/**
 * The credit linking a release to an artist — the only source of truth for a
 * release's artists.
 *
 * @property int $id
 * @property int $release_id
 * @property int $artist_id
 * @property ReleaseArtistRole $role
 * @property int $position
 */
final class ReleaseArtist extends Pivot
{
    protected $table = 'release_artist';

    public $incrementing = true;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['role' => ReleaseArtistRole::class];
    }

    /**
     * @return BelongsTo<Release, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    /**
     * @return BelongsTo<Artist, $this>
     */
    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }
}
