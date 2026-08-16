<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use SaniTube\Artists\Models\Artist;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Catalog\Enums\TrackStatus;
use SaniTube\Catalog\Models\Track;
use SaniTube\Releases\Models\Release;

/**
 * The three lookups the release builder needs, and nothing else.
 *
 * A builder that asked a person to paste a UUID would be a builder nobody
 * finishes a release in. These are the smallest read models that make the
 * pickers work: a bounded, filtered list of things that are *legitimate
 * choices right now*.
 *
 * Bounded is load-bearing. Every one of these caps at {@see self::LIMIT} rows
 * and none of them paginates, because a picker that returns a catalogue is a
 * picker that hangs a browser on an installation with real data in it. A
 * person who cannot find their track in twenty results needs to type more of
 * the title, not scroll further.
 *
 * Each list is filtered by what the *domain* would accept, not by what looks
 * tidy:
 *
 *   - Tracks already on this release are excluded, because `addTrack()` would
 *     refuse them and offering a choice that is always refused is a bug with a
 *     nice error message.
 *   - Artwork is restricted to VERIFIED ARTWORK, the same pair
 *     `Asset::isVerifiedArtwork()` tests and `setCover()` refuses without.
 *
 * These are filters, never authorisation. What a person may *do* with a result
 * is decided by the route middleware and the builder.
 */
final readonly class ReleasePickerQuery
{
    public const LIMIT = 20;

    /**
     * Catalogue tracks that could be added to this release.
     *
     * Releasable status only. A track that is archived cannot go out, and
     * `ValidateRelease` reports it as a blocking error — surfacing it in the
     * picker only moves the discovery to a later, more annoying moment.
     *
     * @return list<array<string, mixed>>
     */
    public function tracks(Release $release, string $term): array
    {
        $already = $release->trackEntries()->pluck('track_id')->all();

        // Releasable is filtered in the query, not after it. Filtering a page
        // of twenty in PHP returns an empty picker whenever the first twenty
        // matches happen to be archived, which looks exactly like "no such
        // track".
        $releasable = array_values(array_filter(
            array_map(static fn (TrackStatus $case): string => $case->value, TrackStatus::cases()),
            static fn (string $value): bool => TrackStatus::from($value)->isReleasable(),
        ));

        $query = Track::query()
            ->whereIn('status', $releasable)
            ->whereNotIn('id', $already === [] ? [0] : $already);

        $this->matching($query, 'title', $term);

        $rows = [];

        foreach ($query->orderBy('title')->limit(self::LIMIT)->get() as $track) {
            $rows[] = [
                'uuid' => $track->uuid,
                'title' => $track->title,
                'version_title' => $track->version_title,
                'duration_ms' => $track->duration_ms,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function artists(string $term): array
    {
        $query = Artist::query();

        $this->matching($query, 'name', $term);

        $rows = [];

        foreach ($query->orderBy('name')->limit(self::LIMIT)->get() as $artist) {
            $rows[] = [
                'uuid' => $artist->uuid,
                'name' => $artist->name,
            ];
        }

        return $rows;
    }

    /**
     * Artwork that could actually be used as a cover.
     *
     * Described, never located — no path, no disk, no signed URL, the same
     * rule every other asset payload here follows.
     *
     * @return list<array<string, mixed>>
     */
    public function artwork(string $term): array
    {
        $query = Asset::query()
            ->where('kind', AssetKind::Artwork->value)
            ->where('status', AssetStatus::Verified->value);

        $this->matching($query, 'original_filename', $term);

        $rows = [];

        foreach ($query->orderBy('original_filename')->limit(self::LIMIT)->get() as $asset) {
            $rows[] = [
                'uuid' => $asset->uuid,
                'original_filename' => $asset->original_filename,
                'width' => $asset->width,
                'height' => $asset->height,
                'byte_size' => $asset->byte_size,
            ];
        }

        return $rows;
    }

    /**
     * A search term is data, not syntax.
     *
     * `%` and `_` are wildcards to LIKE and ordinary characters to the person
     * typing them. Left as syntax, a term of `%` matches everything and the
     * cap silently becomes the whole answer — a search that looks like it
     * worked and did not.
     *
     * Escaped with an explicit `ESCAPE` clause rather than by relying on a
     * default, because there is no shared default: MySQL treats a backslash as
     * an escape inside LIKE and SQLite does not. `LIKE ... ESCAPE` is ANSI SQL
     * and means the same thing on every database ARCH-003 requires, which is
     * what makes the raw fragment acceptable — the term itself is bound, never
     * interpolated.
     *
     * The escape character is `!` rather than the conventional backslash for
     * the same portability reason: MySQL also treats a backslash as an escape
     * inside *string literals*, so `ESCAPE '\'` is an unterminated string
     * there and a valid clause in SQLite. Nothing treats `!` specially.
     *
     * @param  Builder<covariant Model>  $query
     */
    private function matching(Builder $query, string $column, string $term): void
    {
        if ($term === '') {
            return;
        }

        $needle = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);

        $query->whereRaw($column." LIKE ? ESCAPE '!'", ['%'.$needle.'%']);
    }
}
