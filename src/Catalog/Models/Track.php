<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Models;

use Database\Factories\TrackFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use SaniTube\Artists\Models\Artist;
use SaniTube\Assets\Exceptions\AssetTrashException;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Models\AssetLink;
use SaniTube\Assets\Services\TrashAsset;
use SaniTube\Catalog\Enums\TrackArtistRole;
use SaniTube\Catalog\Enums\TrackSource;
use SaniTube\Catalog\Enums\TrackStatus;
use SaniTube\Catalog\Events\TrackMarkedReady;
use SaniTube\Catalog\Exceptions\TrackNotReadyException;
use SaniTube\Contributors\Models\Contributor;
use SaniTube\Foundation\Concerns\HasPublicUuid;
use SaniTube\Foundation\Validation\ValidationIssue;
use SaniTube\Localization\ContentLanguage;
use SaniTube\Releases\Models\Release;

/**
 * A recording.
 *
 * The Track is the master side of the catalogue: it is what a DSP streams and
 * what an ISRC identifies. The song it records is a {@see Composition}, and
 * the two are kept apart so that publishing and master income can be accounted
 * for separately.
 *
 * A track has no `primary_artist_id`. Its artists live in `track_artist`,
 * which is the only source of truth, and a track may legitimately have several
 * primary artists.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $composition_id
 * @property int|null $master_asset_id
 * @property string $title
 * @property string|null $version_title
 * @property int|null $duration_ms
 * @property string $language_code
 * @property bool $is_instrumental
 * @property bool $is_explicit
 * @property int|null $bpm
 * @property string|null $musical_key
 * @property string|null $genre_primary
 * @property string|null $genre_secondary
 * @property int|null $recording_year
 * @property string|null $p_line
 * @property TrackStatus $status
 * @property TrackSource $source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Track extends Model
{
    /** @use HasFactory<TrackFactory> */
    use HasFactory;

    use HasPublicUuid;
    use SoftDeletes;

    protected $table = 'tracks';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TrackStatus::class,
            'source' => TrackSource::class,
            'is_instrumental' => 'boolean',
            'is_explicit' => 'boolean',
        ];
    }

    protected static function newFactory(): TrackFactory
    {
        return TrackFactory::new();
    }

    /**
     * A master a reviewer set aside is not a master.
     *
     * TRASH-001. {@see TrashAsset} has always
     * guarded one direction — an asset a track already uses cannot be trashed,
     * because that leaves the catalogue pointing at nothing. The reverse was
     * never guarded, and it is the direction that reaches a customer: nothing
     * stopped a track being *given* a trashed asset, and nothing looked at
     * `trashed_at` while a delivery package was built. The bytes somebody
     * rejected as a wrong file, a bad transfer or a confirmed duplicate were
     * what left for the distributor, and every screen on the way looked
     * correct, because they all hide trashed assets already.
     *
     * On the model rather than in the two services that assign a master, so
     * that a third one written later inherits the refusal instead of having to
     * remember it. The check runs only when the column is actually being
     * written, so saving a title costs no query.
     */
    protected static function booted(): void
    {
        self::saving(static function (self $track): void {
            if (! $track->isDirty('master_asset_id')) {
                return;
            }

            $master = $track->master_asset_id;

            if ($master === null) {
                return;
            }

            $trashed = Asset::query()
                ->whereKey($master)
                ->whereNotNull('trashed_at')
                ->exists();

            if ($trashed) {
                throw AssetTrashException::trashed();
            }
        });
    }

    /**
     * @return BelongsTo<Composition, $this>
     */
    public function composition(): BelongsTo
    {
        return $this->belongsTo(Composition::class);
    }

    /**
     * The canonical master. Nothing else records this fact.
     *
     * @return BelongsTo<Asset, $this>
     */
    public function masterAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'master_asset_id');
    }

    /**
     * @return BelongsToMany<Artist, $this, TrackArtist, 'pivot'>
     */
    public function artists(): BelongsToMany
    {
        return $this->belongsToMany(Artist::class, 'track_artist')
            ->using(TrackArtist::class)
            ->withPivot(['role', 'position'])
            ->withTimestamps()
            ->orderByPivot('position');
    }

    /**
     * @return BelongsToMany<Artist, $this, TrackArtist, 'pivot'>
     */
    public function primaryArtists(): BelongsToMany
    {
        return $this->artists()->wherePivot('role', TrackArtistRole::Primary->value);
    }

    /**
     * @return BelongsToMany<Contributor, $this, TrackContributor, 'pivot'>
     */
    public function contributors(): BelongsToMany
    {
        return $this->belongsToMany(Contributor::class, 'track_contributor')
            ->using(TrackContributor::class)
            ->withPivot(['role', 'position'])
            ->withTimestamps()
            ->orderByPivot('position');
    }

    /**
     * @return BelongsToMany<Release, $this>
     */
    public function releases(): BelongsToMany
    {
        return $this->belongsToMany(Release::class, 'release_tracks')
            ->withPivot(['disc_number', 'track_number', 'is_focus_track'])
            ->withTimestamps();
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
     * @return HasMany<TrackArtist, $this>
     */
    public function artistCredits(): HasMany
    {
        return $this->hasMany(TrackArtist::class);
    }

    public function contentLanguage(): ContentLanguage
    {
        return ContentLanguage::tryFromCode($this->language_code) ?? ContentLanguage::unknown();
    }

    /**
     * Promote the track to READY (I3).
     *
     * Every condition here is something a distributor will reject the release
     * for. Catching it now costs a validation error; catching it later costs a
     * rejected delivery and a missed release date.
     *
     * @throws TrackNotReadyException
     */
    public function markReady(): self
    {
        $problems = $this->readinessProblems();

        if ($problems !== []) {
            throw TrackNotReadyException::because($this, $problems);
        }

        $this->status = TrackStatus::Ready;
        $this->save();

        event(new TrackMarkedReady($this));

        return $this;
    }

    /**
     * Everything standing between this track and READY.
     *
     * CAT-002: issues rather than sentences, for the same reason REL-002 did
     * it to the release — these are now shown to a person on the track screen,
     * and the platform ships six languages. The conditions are byte-for-byte
     * the ones I3 has always enforced.
     *
     * @return list<ValidationIssue>
     */
    public function readinessProblems(): array
    {
        $problems = [];

        if (trim((string) $this->title) === '') {
            $problems[] = ValidationIssue::error('TRACK_TITLE_REQUIRED', 'title', 'A title is required.');
        }

        if (ContentLanguage::tryFromCode($this->language_code) === null) {
            $problems[] = ValidationIssue::error(
                'TRACK_LANGUAGE_INVALID',
                'language_code',
                sprintf('Language [%s] is not a valid content language.', (string) $this->language_code),
                ['code' => (string) $this->language_code],
            );
        }

        if ($this->primaryArtists()->count() < 1) {
            $problems[] = ValidationIssue::error(
                'TRACK_PRIMARY_ARTIST_REQUIRED',
                'artists',
                'At least one PRIMARY artist is required.',
            );
        }

        if ($this->master_asset_id === null) {
            $problems[] = ValidationIssue::error('TRACK_MASTER_REQUIRED', 'master', 'A master asset is required.');

            return $problems;
        }

        $master = $this->masterAsset()->first();

        if (! $master instanceof Asset) {
            $problems[] = ValidationIssue::error(
                'TRACK_MASTER_MISSING',
                'master',
                'The referenced master asset does not exist.',
            );

            return $problems;
        }

        if (! $master->isVerifiedMaster()) {
            $problems[] = ValidationIssue::error(
                'TRACK_MASTER_NOT_VERIFIED',
                'master',
                'The master asset must be an AUDIO_MASTER with status VERIFIED.',
            );
        }

        return $problems;
    }
}
