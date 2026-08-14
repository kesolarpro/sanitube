<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use SaniTube\Artists\Models\Artist;
use SaniTube\Catalog\Enums\TrackArtistRole;

/**
 * The credit linking a track to an artist.
 *
 * This table is the only source of truth for a track's artists. There is no
 * denormalised column anywhere that could disagree with it.
 *
 * @property int $id
 * @property int $track_id
 * @property int $artist_id
 * @property TrackArtistRole $role
 * @property int $position
 */
final class TrackArtist extends Pivot
{
    protected $table = 'track_artist';

    public $incrementing = true;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['role' => TrackArtistRole::class];
    }

    /**
     * @return BelongsTo<Track, $this>
     */
    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    /**
     * @return BelongsTo<Artist, $this>
     */
    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }
}
