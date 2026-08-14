<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Artists\Models\Artist;
use SaniTube\Assets\Models\Asset;
use SaniTube\Catalog\Enums\TrackArtistRole;
use SaniTube\Catalog\Exceptions\TrackNotReadyException;
use SaniTube\Catalog\Models\Track;
use SaniTube\Releases\Enums\ReleaseArtistRole;
use SaniTube\Releases\Exceptions\ReleaseNotReadyException;
use SaniTube\Releases\Models\Release;
use Tests\TestCase;

/**
 * Artist credits are canonical relations, never denormalised columns.
 *
 * The reason a track and a release both refuse a `primary_artist_id` is
 * visible here: collaborations are ordinary, and a single-artist column would
 * force one of two equal credits to be demoted to fit the schema.
 */
final class ArtistRelationsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_track_supports_two_primary_artists(): void
    {
        $track = Track::factory()->create();
        $first = Artist::factory()->create();
        $second = Artist::factory()->create();

        $track->artists()->attach($first->id, ['role' => TrackArtistRole::Primary->value, 'position' => 0]);
        $track->artists()->attach($second->id, ['role' => TrackArtistRole::Primary->value, 'position' => 1]);

        $this->assertSame(2, $track->primaryArtists()->count());
    }

    #[Test]
    public function a_track_cannot_reach_ready_without_a_primary_artist(): void
    {
        $track = Track::factory()->create([
            'master_asset_id' => Asset::factory()->master()->verified()->create()->id,
        ]);

        $track->artists()->attach(Artist::factory()->create()->id, [
            'role' => TrackArtistRole::Featured->value,
            'position' => 0,
        ]);

        $this->expectException(TrackNotReadyException::class);
        $this->expectExceptionMessage('At least one PRIMARY artist is required.');

        $track->markReady();
    }

    #[Test]
    public function the_same_artist_cannot_hold_the_same_role_twice_on_a_track(): void
    {
        $track = Track::factory()->create();
        $artist = Artist::factory()->create();

        $track->artists()->attach($artist->id, ['role' => TrackArtistRole::Primary->value, 'position' => 0]);

        $this->expectException(QueryException::class);

        $track->artists()->attach($artist->id, ['role' => TrackArtistRole::Primary->value, 'position' => 1]);
    }

    #[Test]
    public function the_same_artist_may_hold_two_different_roles_on_a_track(): void
    {
        // A performer who also remixed their own track is one artist with two
        // credits, and both belong on the release.
        $track = Track::factory()->create();
        $artist = Artist::factory()->create();

        $track->artists()->attach($artist->id, ['role' => TrackArtistRole::Primary->value, 'position' => 0]);
        $track->artists()->attach($artist->id, ['role' => TrackArtistRole::Remixer->value, 'position' => 1]);

        $this->assertSame(2, $track->artists()->count());
    }

    #[Test]
    public function a_release_supports_two_primary_artists(): void
    {
        $release = Release::factory()->create();

        $release->artists()->attach(Artist::factory()->create()->id, [
            'role' => ReleaseArtistRole::Primary->value, 'position' => 0,
        ]);
        $release->artists()->attach(Artist::factory()->create()->id, [
            'role' => ReleaseArtistRole::Primary->value, 'position' => 1,
        ]);

        $this->assertSame(2, $release->primaryArtists()->count());
    }

    #[Test]
    public function a_release_cannot_reach_ready_without_a_primary_artist(): void
    {
        $release = Release::factory()->create([
            'cover_asset_id' => Asset::factory()->artwork()->verified()->create()->id,
        ]);

        $release->tracks()->attach(Track::factory()->ready()->create()->id, [
            'disc_number' => 1, 'track_number' => 1,
        ]);

        $this->expectException(ReleaseNotReadyException::class);
        $this->expectExceptionMessage('At least one PRIMARY artist is required.');

        $release->markReady();
    }

    #[Test]
    public function the_same_artist_cannot_hold_the_same_role_twice_on_a_release(): void
    {
        $release = Release::factory()->create();
        $artist = Artist::factory()->create();

        $release->artists()->attach($artist->id, ['role' => ReleaseArtistRole::Primary->value, 'position' => 0]);

        $this->expectException(QueryException::class);

        $release->artists()->attach($artist->id, ['role' => ReleaseArtistRole::Primary->value, 'position' => 1]);
    }

    #[Test]
    public function no_denormalised_artist_column_exists_anywhere(): void
    {
        // Guards the decision recorded in ADR-0011: any future projection must
        // be derived and rebuildable, never a second source of truth sitting
        // in the canonical tables.
        foreach (['tracks', 'releases', 'release_tracks'] as $table) {
            foreach (['primary_artist_id', 'display_artist', 'artist_display_name'] as $column) {
                $this->assertFalse(
                    Schema::hasColumn($table, $column),
                    sprintf('[%s.%s] would be a second source of truth for artist credits.', $table, $column),
                );
            }
        }
    }
}
