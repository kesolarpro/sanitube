<?php

declare(strict_types=1);

namespace Tests\Feature\Releases;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Artists\Models\Artist;
use SaniTube\Artwork\ArtworkMeasurement;
use SaniTube\Artwork\Contracts\ImageMeasurer;
use SaniTube\Artwork\Services\MeasureArtwork;
use SaniTube\Artwork\Testing\FakeImageMeasurer;
use SaniTube\Assets\Models\Asset;
use SaniTube\Catalog\Enums\CompositionContributorRole;
use SaniTube\Catalog\Enums\ExternalIdentifierSource;
use SaniTube\Catalog\Enums\ExternalIdentifierType;
use SaniTube\Catalog\Enums\TrackArtistRole;
use SaniTube\Catalog\Enums\TrackContributorRole;
use SaniTube\Catalog\Models\Composition;
use SaniTube\Catalog\Models\ExternalIdentifier;
use SaniTube\Catalog\Models\Track;
use SaniTube\Catalog\Services\AssignExternalIdentifier;
use SaniTube\Catalog\Services\RevokeExternalIdentifier;
use SaniTube\Contributors\Models\Contributor;
use SaniTube\Releases\Enums\ReleaseArtistRole;
use SaniTube\Releases\Exceptions\ReleasePackagingException;
use SaniTube\Releases\Models\Release;
use SaniTube\Releases\Packaging\PackageRelease;
use SaniTube\Releases\Packaging\ReleasePackage;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * REL-003 — the one description of a release that crosses to a distributor.
 *
 * Before this, an adapter would have walked the Release aggregate itself —
 * reaching into tracks, credits, identifiers and assets. Which means every
 * adapter reaches differently, every adapter gets its own chance to read a
 * revoked identifier as current, and "what did we actually send" is answerable
 * only by re-running whatever that adapter happened to do.
 *
 * Three claims carry the ticket, and each is a negative:
 *
 * - **A revoked identifier never appears as current.** The specific mistake
 *   ARCH-002's active marker exists to prevent, tested here so the package
 *   cannot be the place it happens anyway.
 * - **No location and no secret is anywhere in the structure.** Asserted by
 *   walking the whole serialised package, not by inspecting the fields somebody
 *   remembered to check.
 * - **Nothing here holds money.** Rights metadata yes; a price, a rate or a
 *   split, never.
 */
final class ReleasePackagingTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryStorageProvider $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new InMemoryStorageProvider('memory');
        config(['storage.default' => 'memory']);
        $this->app->make(StorageManager::class)->register('memory', $this->store);

        // ART-001 would otherwise report every cover as unmeasured, which is a
        // warning rather than an error and so does not block packaging — but
        // measuring makes these fixtures describe a release somebody could
        // actually deliver.
        $this->app->instance(ImageMeasurer::class, new FakeImageMeasurer(
            new ArtworkMeasurement(3000, 3000, 'image/jpeg', 900_000, channels: 3),
        ));
    }

    // ------------------------------------------------------- what it refuses

    #[Test]
    public function an_invalid_release_is_refused_rather_than_packaged(): void
    {
        // Packaging an invalid release does not make it valid. It moves the
        // rejection from somewhere this platform controls to somewhere it does
        // not, after a delivery has partly happened.
        $release = Release::factory()->create(['cover_asset_id' => null, 'release_date' => null]);

        try {
            $this->packager()->handle($release);
            $this->fail('An invalid release must not be packaged.');
        } catch (ReleasePackagingException $exception) {
            $this->assertSame('RELEASE_NOT_VALID', $exception->reason);
            // Names what is wrong, so a caller can act rather than only learn
            // that something was.
            $this->assertNotEmpty($exception->context);
        }
    }

    #[Test]
    public function a_release_with_no_tracks_is_refused(): void
    {
        $release = $this->validRelease();
        $release->tracks()->detach();

        $this->expectException(ReleasePackagingException::class);
        $this->packager()->handle($release->refresh());
    }

    #[Test]
    public function a_track_with_no_master_is_an_error_and_never_a_silent_omission(): void
    {
        // Dropping it would deliver a release missing a track, and nobody would
        // find out until a store published a two-track single as one.
        $release = $this->validRelease();

        $track = Track::factory()->ready()->create();
        $release->tracks()->attach($track->id, ['disc_number' => 1, 'track_number' => 2]);
        $track->forceFill(['master_asset_id' => null])->save();

        try {
            $this->packager()->handle($release->refresh());
            $this->fail('A track with no master must be refused.');
        } catch (ReleasePackagingException $exception) {
            $this->assertContains($exception->reason, ['TRACK_WITHOUT_MASTER', 'RELEASE_NOT_VALID']);
        }
    }

    // ---------------------------------------------------- what it carries

    #[Test]
    public function a_valid_release_packages_its_tracks_in_order(): void
    {
        $release = $this->validRelease();

        $second = Track::factory()->ready()->create(['title' => 'Second']);
        $release->tracks()->attach($second->id, ['disc_number' => 1, 'track_number' => 2]);

        $package = $this->packager()->handle($release->refresh());

        $this->assertInstanceOf(ReleasePackage::class, $package);
        $this->assertSame(2, $package->trackCount());
        $this->assertSame([1, 2], array_map(
            static fn ($track): int => $track->trackNumber,
            $package->tracks,
        ));
    }

    #[Test]
    public function an_active_identifier_is_carried_and_a_revoked_one_is_not(): void
    {
        // The load-bearing claim. A revoked identifier is still a row, on
        // purpose, so the history stays readable — and handing one to a
        // distributor as current is exactly what the active marker exists to
        // prevent.
        $release = $this->validRelease();
        $track = $release->tracks()->firstOrFail();

        $this->revoke($this->identifier($track, ExternalIdentifierType::Isrc, 'GBXXX0000001'));

        $package = $this->packager()->handle($release->refresh());
        $this->assertNull($package->tracks[0]->isrc, 'A revoked ISRC was presented as current.');

        $this->identifier($track, ExternalIdentifierType::Isrc, 'GBXXX0000002');

        $package = $this->packager()->handle($release->refresh());
        $this->assertSame('GBXXX0000002', $package->tracks[0]->isrc);
    }

    #[Test]
    public function a_missing_identifier_is_null_and_never_invented(): void
    {
        // SaniTube never invents an ISRC, so a track may legitimately arrive
        // without one and let the distributor assign it. The nullable field is
        // the honest answer; a fabricated one is not.
        $package = $this->packager()->handle($this->validRelease());

        $this->assertNull($package->tracks[0]->isrc);
        $this->assertNull($package->upc);
    }

    #[Test]
    public function credits_are_carried_with_their_roles(): void
    {
        $release = $this->validRelease();
        $track = $release->tracks()->firstOrFail();

        // A *different* display name, or the assertion below proves nothing:
        // the factory leaves display_name null, so legal_name and any fallback
        // to it are the same string and a mutation swapping them survives.
        $contributor = Contributor::factory()->create([
            'legal_name' => 'A Real Legal Name',
            'display_name' => 'A Stage Name',
        ]);
        $track->contributors()->attach($contributor->id, [
            'role' => TrackContributorRole::Producer->value,
            'position' => 0,
        ]);

        $package = $this->packager()->handle($release->refresh());
        $packaged = $package->tracks[0];

        $this->assertNotEmpty($packaged->artists);
        $this->assertSame(TrackArtistRole::Primary->value, $packaged->artists[0]->role);

        $this->assertCount(1, $packaged->contributors);
        // The legal name, not the display name: a distributor passes this to
        // collecting societies, which match on the registered name.
        $this->assertSame('A Real Legal Name', $packaged->contributors[0]->name);
        $this->assertStringNotContainsString('Stage', $packaged->contributors[0]->name);
        $this->assertSame(TrackContributorRole::Producer->value, $packaged->contributors[0]->role);
    }

    #[Test]
    public function a_track_with_no_line_of_its_own_inherits_the_releases(): void
    {
        // A store prints one per recording. A track that says nothing would be
        // published with no ownership line at all rather than the label's.
        $release = $this->validRelease();
        $release->forceFill(['p_line' => '℗ 2026 A Label'])->save();
        $release->tracks()->firstOrFail()->forceFill(['p_line' => null])->save();

        $package = $this->packager()->handle($release->refresh());

        $this->assertSame('℗ 2026 A Label', $package->tracks[0]->pLine);
    }

    // ------------------------------------------------- what it must not carry

    #[Test]
    public function no_location_and_no_secret_is_anywhere_in_the_package(): void
    {
        $release = $this->validRelease();
        $cover = $release->coverAsset;
        $master = $release->tracks()->firstOrFail()->masterAsset;

        $this->assertNotNull($cover);
        $this->assertNotNull($master);

        $encoded = json_encode($this->packager()->handle($release)->toArray());
        $this->assertIsString($encoded);

        // Named one by one and each asserted non-empty first, so the loop
        // cannot quietly check fewer things than it claims — the defect ENR-005
        // shipped with before mutation caught it.
        foreach ([
            'cover path' => $cover->path,
            'cover disk' => $cover->disk,
            'cover filename' => $cover->original_filename,
            'master path' => $master->path,
            'master disk' => $master->disk,
            'master checksum' => $master->sha256,
        ] as $label => $secret) {
            $this->assertNotEmpty($secret, $label.' is empty, so asserting on it proves nothing.');
            $this->assertStringNotContainsString((string) $secret, $encoded, $label.' leaked into the package.');
        }

        // The assets are still identified — by uuid, which is the whole point.
        $this->assertStringContainsString($cover->uuid, $encoded);
        $this->assertStringContainsString($master->uuid, $encoded);
    }

    #[Test]
    public function the_package_holds_no_money(): void
    {
        // Rights metadata yes — a distributor needs ℗ and © lines and
        // contributor roles to publish. A price, a rate or a split, never.
        $source = '';

        foreach ([
            'src/Releases/Packaging/ReleasePackage.php',
            'src/Releases/Packaging/PackagedTrack.php',
            'src/Releases/Packaging/PackagedArtist.php',
            'src/Releases/Packaging/PackagedContributor.php',
            'src/Releases/Packaging/PackageRelease.php',
        ] as $file) {
            foreach (token_get_all((string) file_get_contents(base_path($file))) as $token) {
                // Comments stripped and code alone scanned: the docblocks
                // *promise* there is no money here, and an earlier version of
                // this test in ENR-003 failed on its own explanation of itself.
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $source .= is_array($token) ? $token[1] : $token;
            }
        }

        foreach (['price', 'royalt', 'payout', 'revenue', 'balance', 'currency', 'invoice', 'split'] as $word) {
            $this->assertStringNotContainsString($word, strtolower($source));
        }
    }

    #[Test]
    public function the_serialised_shape_is_deliberate_and_not_reflected(): void
    {
        // What leaves this platform for a distributor should change only when
        // somebody decides it should. A reflective serialiser would quietly
        // export any property a later ticket adds.
        $package = $this->packager()->handle($this->validRelease());
        $array = $package->toArray();

        $this->assertSame([
            'uuid', 'type', 'title', 'version_title', 'upc', 'catalogue_number', 'label_name',
            'release_date', 'original_release_date', 'language_code', 'p_line', 'c_line',
            'cover_asset_uuid', 'artists', 'tracks',
        ], array_keys($array));

        $this->assertSame([
            'uuid', 'disc_number', 'track_number', 'title', 'version_title', 'isrc', 'language_code',
            'is_instrumental', 'is_explicit', 'duration_ms', 'p_line', 'genre_primary',
            'genre_secondary', 'master_asset_uuid', 'is_focus_track', 'artists', 'contributors',

            // DIST-008. The work alongside the recording: a composition's own
            // identifier, and the people who wrote it. Added here deliberately,
            // which is what this test exists to require.
            'iswc', 'writers',
        ], array_keys($array['tracks'][0]));
    }

    // ----------------------------------------------------------- fixtures

    /**
     * DIST-008. The work crosses, not only the recording.
     *
     * The catalogue held composers, lyricists and publishers with their IPIs
     * and the composition's ISWC, and the package described only who
     * engineered the master. `trackContributors()` even explained itself by
     * saying a distributor forwards the name to collecting societies — which is
     * the writer use case, applied to a relation that can only hold engineers.
     */
    #[Test]
    public function the_work_and_the_people_who_wrote_it_reach_the_package(): void
    {
        $release = $this->releaseWithAWork();

        $track = $this->packager()->handle($release)->tracks[0];

        $this->assertSame('T-034.524.680-1', $track->iswc);
        $this->assertCount(2, $track->writers);

        // Ordered as somebody entered them. A society is told who is first.
        $this->assertSame('Bacharach, Burt', $track->writers[0]->name);
        $this->assertSame('COMPOSER', $track->writers[0]->role);
        $this->assertSame('00052210182', $track->writers[0]->ipi);

        $this->assertSame('David, Hal', $track->writers[1]->name);
        $this->assertSame('LYRICIST', $track->writers[1]->role);
    }

    #[Test]
    public function a_recording_of_a_work_nobody_has_entered_says_so(): void
    {
        // The ordinary state, and it must not be an error. SaniTube never
        // invents an identifier and never invents a composition either.
        $track = $this->packager()->handle($this->validRelease())->tracks[0];

        $this->assertNull($track->iswc);
        $this->assertSame([], $track->writers);
    }

    #[Test]
    public function a_writers_share_is_carried_as_captured_and_never_computed(): void
    {
        // Rights metadata, which a society requires, and not money. The
        // platform stores what somebody entered and passes it through exactly
        // as the column holds it — six decimal places and all. Rounding it
        // here would be this platform having an opinion about a split it was
        // told, and nothing here adds, splits or converts anything.
        $release = $this->releaseWithAWork();

        $writers = $this->packager()->handle($release)->tracks[0]->writers;

        $this->assertSame('60.000000', $writers[0]->share);
        $this->assertSame('40.000000', $writers[1]->share);
    }

    #[Test]
    public function a_revoked_work_identifier_is_never_presented_as_current(): void
    {
        $release = $this->releaseWithAWork();

        $composition = $release->tracks()->firstOrFail()->composition;
        $this->assertInstanceOf(Composition::class, $composition);

        $iswc = $composition->externalIdentifiers()
            ->where('type', ExternalIdentifierType::Iswc->value)
            ->firstOrFail();

        ($this->app->make(RevokeExternalIdentifier::class))($iswc, 'Registered against the wrong work.');

        // The same rule the ISRC has had since REL-003: a withdrawn code that
        // reappeared in a delivery would put it back into circulation.
        $this->assertNull($this->packager()->handle($release->refresh())->tracks[0]->iswc);
    }

    /**
     * A release whose one track is a recording of a composition with two
     * writers and an ISWC.
     */
    private function releaseWithAWork(): Release
    {
        $release = $this->validRelease();

        $composition = Composition::factory()->create(['title' => 'Night Bus']);

        $composition->externalIdentifiers()->create([
            'type' => ExternalIdentifierType::Iswc,
            'value' => 'T-034.524.680-1',
            'source' => ExternalIdentifierSource::Manual,
            'assigned_at' => now(),
        ]);

        $composer = Contributor::factory()->create(['legal_name' => 'Bacharach, Burt']);
        $composer->externalIdentifiers()->create([
            'type' => ExternalIdentifierType::Ipi,
            'value' => '00052210182',
            'source' => ExternalIdentifierSource::Manual,
            'assigned_at' => now(),
        ]);

        $lyricist = Contributor::factory()->create(['legal_name' => 'David, Hal']);

        $composition->contributors()->attach($composer->id, [
            'role' => CompositionContributorRole::Composer->value,
            'share' => '60.00',
            'position' => 0,
        ]);

        $composition->contributors()->attach($lyricist->id, [
            'role' => CompositionContributorRole::Lyricist->value,
            'share' => '40.00',
            'position' => 1,
        ]);

        $track = $release->tracks()->firstOrFail();
        $track->forceFill(['composition_id' => $composition->id])->save();

        return $release->refresh();
    }

    private function validRelease(): Release
    {
        $cover = Asset::factory()->artwork()->verified()->create(['disk' => 'memory']);
        $this->store->put($cover->path, 'cover-bytes');
        $this->app->make(MeasureArtwork::class)->handle($cover);

        $release = Release::factory()->create([
            'cover_asset_id' => $cover->id,
            'release_date' => now()->addMonth()->toDateString(),
        ]);

        $release->artists()->attach(Artist::factory()->create()->id, [
            'role' => ReleaseArtistRole::Primary->value,
            'position' => 0,
        ]);

        $release->tracks()->attach(Track::factory()->ready()->create()->id, [
            'disc_number' => 1,
            'track_number' => 1,
            'is_focus_track' => true,
        ]);

        return $release->refresh();
    }

    /**
     * Assigned through the platform's own service, not hand-built.
     *
     * A row inserted directly can be a shape the platform would never produce —
     * and then the test proves the packager handles something that cannot
     * happen while saying nothing about what does.
     */
    private function identifier(Track $track, ExternalIdentifierType $type, string $value): ExternalIdentifier
    {
        return ($this->app->make(AssignExternalIdentifier::class))($track, $type, $value);
    }

    private function revoke(ExternalIdentifier $identifier): void
    {
        ($this->app->make(RevokeExternalIdentifier::class))($identifier, 'Withdrawn by the test.');
    }

    private function packager(): PackageRelease
    {
        return $this->app->make(PackageRelease::class);
    }
}
