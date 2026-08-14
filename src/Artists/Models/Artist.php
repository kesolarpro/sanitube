<?php

declare(strict_types=1);

namespace SaniTube\Artists\Models;

use Database\Factories\ArtistFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use SaniTube\Artists\Enums\ArtistStatus;
use SaniTube\Artists\Enums\ArtistType;
use SaniTube\Catalog\Enums\TrackArtistRole;
use SaniTube\Catalog\Models\ExternalIdentifier;
use SaniTube\Catalog\Models\Track;
use SaniTube\Contributors\Models\Contributor;
use SaniTube\Foundation\Concerns\HasPublicUuid;
use SaniTube\Releases\Enums\ReleaseArtistRole;
use SaniTube\Releases\Models\Release;

/**
 * A public credit.
 *
 * An Artist is the name that appears on a release, which is not the same thing
 * as the person or company behind it — see {@see Contributor}
 * for that. One person can be several artists, an artist can be several
 * people, and "Various Artists" is nobody at all. Rights never attach here.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $sort_name
 * @property string $slug
 * @property ArtistType $type
 * @property string|null $country
 * @property string $primary_locale
 * @property string|null $biography
 * @property bool $is_owner_controlled
 * @property ArtistStatus $status
 */
final class Artist extends Model
{
    /** @use HasFactory<ArtistFactory> */
    use HasFactory;

    use HasPublicUuid;
    use SoftDeletes;

    protected $table = 'artists';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ArtistType::class,
            'status' => ArtistStatus::class,
            'is_owner_controlled' => 'boolean',
        ];
    }

    protected static function newFactory(): ArtistFactory
    {
        return ArtistFactory::new();
    }

    /**
     * @return BelongsToMany<Track, $this>
     */
    public function tracks(): BelongsToMany
    {
        return $this->belongsToMany(Track::class, 'track_artist')
            ->withPivot(['role', 'position'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Track, $this>
     */
    public function primaryTracks(): BelongsToMany
    {
        return $this->tracks()->wherePivot('role', TrackArtistRole::Primary->value);
    }

    /**
     * @return BelongsToMany<Release, $this>
     */
    public function releases(): BelongsToMany
    {
        return $this->belongsToMany(Release::class, 'release_artist')
            ->withPivot(['role', 'position'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Release, $this>
     */
    public function primaryReleases(): BelongsToMany
    {
        return $this->releases()->wherePivot('role', ReleaseArtistRole::Primary->value);
    }

    /**
     * @return MorphMany<ExternalIdentifier, $this>
     */
    public function externalIdentifiers(): MorphMany
    {
        return $this->morphMany(ExternalIdentifier::class, 'identifiable');
    }
}
