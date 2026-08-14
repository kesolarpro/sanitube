<?php

declare(strict_types=1);

namespace SaniTube\Releases\Models;

use Database\Factories\ReleaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use SaniTube\Artists\Models\Artist;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Models\AssetLink;
use SaniTube\Catalog\Models\ExternalIdentifier;
use SaniTube\Catalog\Models\Track;
use SaniTube\Foundation\Concerns\HasPublicUuid;
use SaniTube\Releases\Enums\ReleaseArtistRole;
use SaniTube\Releases\Enums\ReleaseStatus;
use SaniTube\Releases\Enums\ReleaseType;
use SaniTube\Releases\Events\ReleaseMarkedReady;
use SaniTube\Releases\Exceptions\ReleaseNotReadyException;

/**
 * What actually gets delivered to stores.
 *
 * A release is a package of existing recordings, not a container that owns
 * them: the same track can appear on a single, an album and a compilation
 * without a byte being copied. Like a track, it has no `primary_artist_id` —
 * `release_artist` is the only source of truth for its credits.
 *
 * @property int $id
 * @property string $uuid
 * @property ReleaseType $type
 * @property string $title
 * @property string|null $version_title
 * @property int|null $cover_asset_id
 * @property string|null $label_name
 * @property string|null $catalogue_number
 * @property Carbon|null $release_date
 * @property Carbon|null $original_release_date
 * @property string $language_code
 * @property string|null $p_line
 * @property string|null $c_line
 * @property ReleaseStatus $status
 */
final class Release extends Model
{
    /** @use HasFactory<ReleaseFactory> */
    use HasFactory;

    use HasPublicUuid;
    use SoftDeletes;

    protected $table = 'releases';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ReleaseType::class,
            'status' => ReleaseStatus::class,
            'release_date' => 'date',
            'original_release_date' => 'date',
        ];
    }

    protected static function newFactory(): ReleaseFactory
    {
        return ReleaseFactory::new();
    }

    /**
     * The canonical cover. Nothing else records this fact.
     *
     * @return BelongsTo<Asset, $this>
     */
    public function coverAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'cover_asset_id');
    }

    /**
     * @return BelongsToMany<Artist, $this>
     */
    public function artists(): BelongsToMany
    {
        return $this->belongsToMany(Artist::class, 'release_artist')
            ->using(ReleaseArtist::class)
            ->withPivot(['role', 'position'])
            ->withTimestamps()
            ->orderByPivot('position');
    }

    /**
     * @return BelongsToMany<Artist, $this>
     */
    public function primaryArtists(): BelongsToMany
    {
        return $this->artists()->wherePivot('role', ReleaseArtistRole::Primary->value);
    }

    /**
     * @return BelongsToMany<Track, $this>
     */
    public function tracks(): BelongsToMany
    {
        return $this->belongsToMany(Track::class, 'release_tracks')
            ->using(ReleaseTrack::class)
            ->withPivot(['disc_number', 'track_number', 'is_focus_track'])
            ->withTimestamps()
            ->orderByPivot('disc_number')
            ->orderByPivot('track_number');
    }

    /**
     * @return HasMany<ReleaseTrack, $this>
     */
    public function trackEntries(): HasMany
    {
        return $this->hasMany(ReleaseTrack::class);
    }

    /**
     * @return MorphMany<AssetLink, $this>
     */
    public function assetLinks(): MorphMany
    {
        return $this->morphMany(AssetLink::class, 'linkable');
    }

    /**
     * @return MorphMany<ExternalIdentifier, $this>
     */
    public function externalIdentifiers(): MorphMany
    {
        return $this->morphMany(ExternalIdentifier::class, 'identifiable');
    }

    /**
     * Promote the release to READY (I4 and I9).
     *
     * @throws ReleaseNotReadyException
     */
    public function markReady(): self
    {
        $problems = $this->readinessProblems();

        if ($problems !== []) {
            throw ReleaseNotReadyException::because($this, $problems);
        }

        $this->status = ReleaseStatus::Ready;
        $this->save();

        event(new ReleaseMarkedReady($this));

        return $this;
    }

    /**
     * @return list<string>
     */
    public function readinessProblems(): array
    {
        $problems = [];

        if ($this->primaryArtists()->count() < 1) {
            $problems[] = 'At least one PRIMARY artist is required.';
        }

        if ($this->release_date === null) {
            $problems[] = 'A release date is required.';
        }

        $problems = array_merge($problems, $this->coverProblems(), $this->tracklistProblems());

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function coverProblems(): array
    {
        if ($this->cover_asset_id === null) {
            return ['A cover asset is required.'];
        }

        $cover = $this->coverAsset()->first();

        if (! $cover instanceof Asset) {
            return ['The referenced cover asset does not exist.'];
        }

        return $cover->isVerifiedArtwork()
            ? []
            : ['The cover asset must be ARTWORK with status VERIFIED.'];
    }

    /**
     * Track numbering must be contiguous from 1 within each disc (I9). A gap
     * is almost always a track that was meant to be there and is not.
     *
     * @return list<string>
     */
    private function tracklistProblems(): array
    {
        /** @var Collection<int, ReleaseTrack> $entries */
        $entries = $this->trackEntries()->with('track')->get();

        if ($entries->isEmpty()) {
            return ['At least one track is required.'];
        }

        $problems = [];

        foreach ($entries as $entry) {
            $track = $entry->track;

            if ($track === null || ! $track->status->isReleasable()) {
                $problems[] = sprintf(
                    'Track #%d (disc %d) must be READY or RELEASED.',
                    $entry->track_number,
                    $entry->disc_number,
                );
            }
        }

        foreach ($entries->groupBy('disc_number') as $disc => $discEntries) {
            $numbers = $discEntries->pluck('track_number')->map(intval(...))->sort()->values()->all();
            $expected = range(1, count($numbers));

            if ($numbers !== $expected) {
                $problems[] = sprintf(
                    'Disc %s must be numbered contiguously from 1; found [%s].',
                    (string) $disc,
                    implode(', ', $numbers),
                );
            }
        }

        return $problems;
    }
}
