<?php

declare(strict_types=1);

namespace SaniTube\Releases\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use SaniTube\Catalog\Models\Track;

/**
 * A track's place in a release's running order.
 *
 * The master is not duplicated here: a track appearing on three releases is
 * three rows in this table pointing at one recording, which is what makes a
 * compilation cost nothing extra in storage.
 *
 * @property int $id
 * @property int $release_id
 * @property int $track_id
 * @property int $disc_number
 * @property int $track_number
 * @property bool $is_focus_track
 */
final class ReleaseTrack extends Pivot
{
    protected $table = 'release_tracks';

    public $incrementing = true;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_focus_track' => 'boolean'];
    }

    /**
     * @return BelongsTo<Release, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    /**
     * @return BelongsTo<Track, $this>
     */
    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }
}
