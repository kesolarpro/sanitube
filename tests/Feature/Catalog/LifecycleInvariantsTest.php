<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Artists\Models\Artist;
use SaniTube\Assets\Models\Asset;
use SaniTube\Catalog\Enums\CompositionContributorRole;
use SaniTube\Catalog\Enums\TrackArtistRole;
use SaniTube\Catalog\Enums\TrackStatus;
use SaniTube\Catalog\Exceptions\CompositionShareException;
use SaniTube\Catalog\Exceptions\TrackDeletionException;
use SaniTube\Catalog\Exceptions\TrackLanguageException;
use SaniTube\Catalog\Exceptions\TrackNotReadyException;
use SaniTube\Catalog\Models\Composition;
use SaniTube\Catalog\Models\Track;
use SaniTube\Contributors\Models\Contributor;
use SaniTube\Localization\ContentLanguage;
use SaniTube\Releases\Enums\ReleaseArtistRole;
use SaniTube\Releases\Enums\ReleaseStatus;
use SaniTube\Releases\Exceptions\ReleaseDeletionException;
use SaniTube\Releases\Exceptions\ReleaseNotReadyException;
use SaniTube\Releases\Models\Release;
use Tests\TestCase;

/**
 * Invariants I3, I4, I6, I7, I8 and I9.
 */
final class LifecycleInvariantsTest extends TestCase
{
    use RefreshDatabase;

    // I3 — track readiness

    #[Test]
    public function a_track_cannot_reach_ready_without_a_master(): void
    {
        $track = Track::factory()->create();
        $track->artists()->attach(Artist::factory()->create()->id, [
            'role' => TrackArtistRole::Primary->value, 'position' => 0,
        ]);

        $this->expectException(TrackNotReadyException::class);
        $this->expectExceptionMessage('A master asset is required.');

        $track->markReady();
    }

    #[Test]
    public function a_track_cannot_reach_ready_with_an_unverified_master(): void
    {
        // A master that has been stored but not re-read is not yet known to
        // match its checksum, and shipping it would mean shipping unverified
        // audio.
        $track = Track::factory()->create([
            'master_asset_id' => Asset::factory()->master()->stored()->create()->id,
        ]);
        $track->artists()->attach(Artist::factory()->create()->id, [
            'role' => TrackArtistRole::Primary->value, 'position' => 0,
        ]);

        $this->expectException(TrackNotReadyException::class);
        $this->expectExceptionMessage('must be an AUDIO_MASTER with status VERIFIED');

        $track->markReady();
    }

    #[Test]
    public function a_track_cannot_reach_ready_with_artwork_as_its_master(): void
    {
        $track = Track::factory()->create([
            'master_asset_id' => Asset::factory()->artwork()->verified()->create()->id,
        ]);
        $track->artists()->attach(Artist::factory()->create()->id, [
            'role' => TrackArtistRole::Primary->value, 'position' => 0,
        ]);

        $this->expectException(TrackNotReadyException::class);

        $track->markReady();
    }

    // I8 — instrumental and language agree

    #[Test]
    public function an_instrumental_must_declare_no_linguistic_content(): void
    {
        $this->expectException(TrackLanguageException::class);

        Track::factory()->create(['is_instrumental' => true, 'language_code' => 'fr']);
    }

    #[Test]
    public function a_track_in_zxx_must_be_marked_instrumental(): void
    {
        $this->expectException(TrackLanguageException::class);

        Track::factory()->create([
            'is_instrumental' => false,
            'language_code' => ContentLanguage::INSTRUMENTAL,
        ]);
    }

    #[Test]
    public function a_consistent_instrumental_is_accepted(): void
    {
        $track = Track::factory()->instrumental()->create();

        $this->assertTrue($track->contentLanguage()->isInstrumental());
        $this->assertFalse($track->contentLanguage()->allowsLyrics());
    }

    // I7 — credited shares

    #[Test]
    public function credited_shares_for_one_role_cannot_exceed_one_hundred(): void
    {
        $composition = Composition::factory()->create();

        $composition->contributors()->attach(Contributor::factory()->create()->id, [
            'role' => CompositionContributorRole::Composer->value, 'share' => 60, 'position' => 0,
        ]);

        $this->expectException(CompositionShareException::class);
        $this->expectExceptionMessage('exceeds 100%');

        $composition->contributors()->attach(Contributor::factory()->create()->id, [
            'role' => CompositionContributorRole::Composer->value, 'share' => 50, 'position' => 1,
        ]);
    }

    #[Test]
    public function two_roles_may_each_account_to_one_hundred(): void
    {
        // Writers and publishers each account to 100% of their own side;
        // summing across roles would reject ordinary paperwork.
        $composition = Composition::factory()->create();

        $composition->contributors()->attach(Contributor::factory()->create()->id, [
            'role' => CompositionContributorRole::Composer->value, 'share' => 100, 'position' => 0,
        ]);

        $composition->contributors()->attach(Contributor::factory()->organisation()->create()->id, [
            'role' => CompositionContributorRole::Publisher->value, 'share' => 100, 'position' => 0,
        ]);

        $this->assertSame(2, $composition->contributorCredits()->count());
    }

    // I4 and I9 — release readiness

    #[Test]
    public function a_release_cannot_reach_ready_without_a_verified_cover(): void
    {
        $release = $this->releaseWithArtistAndTrack(cover: null);

        $this->expectException(ReleaseNotReadyException::class);
        $this->expectExceptionMessage('A cover asset is required.');

        $release->markReady();
    }

    #[Test]
    public function a_release_cannot_reach_ready_with_an_unverified_cover(): void
    {
        $release = $this->releaseWithArtistAndTrack(
            cover: Asset::factory()->artwork()->stored()->create(),
        );

        $this->expectException(ReleaseNotReadyException::class);
        $this->expectExceptionMessage('must be ARTWORK with status VERIFIED');

        $release->markReady();
    }

    #[Test]
    public function a_release_cannot_reach_ready_with_no_tracks(): void
    {
        $release = Release::factory()->create([
            'cover_asset_id' => Asset::factory()->artwork()->verified()->create()->id,
        ]);
        $release->artists()->attach(Artist::factory()->create()->id, [
            'role' => ReleaseArtistRole::Primary->value, 'position' => 0,
        ]);

        $this->expectException(ReleaseNotReadyException::class);
        $this->expectExceptionMessage('At least one track is required.');

        $release->markReady();
    }

    #[Test]
    public function a_release_cannot_reach_ready_with_a_draft_track(): void
    {
        $release = $this->releaseWithArtistAndTrack(
            cover: Asset::factory()->artwork()->verified()->create(),
            track: Track::factory()->create(),
        );

        $this->expectException(ReleaseNotReadyException::class);
        $this->expectExceptionMessage('must be READY or RELEASED');

        $release->markReady();
    }

    #[Test]
    public function a_release_cannot_reach_ready_with_a_gap_in_its_numbering(): void
    {
        // A gap is almost always a track that was meant to be there and is
        // not — far cheaper to catch here than after delivery.
        $release = Release::factory()->create([
            'cover_asset_id' => Asset::factory()->artwork()->verified()->create()->id,
        ]);
        $release->artists()->attach(Artist::factory()->create()->id, [
            'role' => ReleaseArtistRole::Primary->value, 'position' => 0,
        ]);

        $release->tracks()->attach(Track::factory()->ready()->create()->id, [
            'disc_number' => 1, 'track_number' => 1,
        ]);
        $release->tracks()->attach(Track::factory()->ready()->create()->id, [
            'disc_number' => 1, 'track_number' => 3,
        ]);

        $this->expectException(ReleaseNotReadyException::class);
        $this->expectExceptionMessage('numbered contiguously from 1');

        $release->refresh()->markReady();
    }

    #[Test]
    public function each_disc_is_numbered_from_one_independently(): void
    {
        $release = Release::factory()->album()->create([
            'cover_asset_id' => Asset::factory()->artwork()->verified()->create()->id,
        ]);
        $release->artists()->attach(Artist::factory()->create()->id, [
            'role' => ReleaseArtistRole::Primary->value, 'position' => 0,
        ]);

        foreach ([[1, 1], [1, 2], [2, 1]] as [$disc, $number]) {
            $release->tracks()->attach(Track::factory()->ready()->create()->id, [
                'disc_number' => $disc, 'track_number' => $number,
            ]);
        }

        $release->refresh()->markReady();

        $this->assertSame(ReleaseStatus::Ready, $release->refresh()->status);
    }

    // I6 — deletion rules

    #[Test]
    public function a_track_on_a_submitted_release_cannot_be_deleted(): void
    {
        foreach ([ReleaseStatus::Submitted, ReleaseStatus::Delivered, ReleaseStatus::Live] as $status) {
            $release = Release::factory()->ready()->create();
            $release->status = $status;
            $release->save();

            $track = $release->tracks()->first();
            $this->assertNotNull($track);

            try {
                $track->delete();
                $this->fail(sprintf('A track on a %s release was deleted.', $status->value));
            } catch (TrackDeletionException $exception) {
                $this->assertStringContainsString('committed release', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function a_track_on_a_draft_release_can_still_be_deleted(): void
    {
        $release = Release::factory()->create();
        $track = Track::factory()->create();
        $release->tracks()->attach($track->id, ['disc_number' => 1, 'track_number' => 1]);

        $release->tracks()->detach($track->id);
        $track->delete();

        $this->assertSoftDeleted($track);
    }

    #[Test]
    public function only_a_draft_release_can_be_deleted(): void
    {
        $draft = Release::factory()->create();
        $draft->delete();
        $this->assertSoftDeleted($draft);

        foreach ([ReleaseStatus::Ready, ReleaseStatus::Submitted, ReleaseStatus::Delivered, ReleaseStatus::Live] as $status) {
            $release = Release::factory()->create();
            $release->status = $status;
            $release->save();

            try {
                $release->delete();
                $this->fail(sprintf('A %s release was deleted.', $status->value));
            } catch (ReleaseDeletionException $exception) {
                $this->assertStringContainsString($status->value, $exception->getMessage());
            }
        }
    }

    #[Test]
    public function a_ready_track_reports_its_status_correctly(): void
    {
        $track = Track::factory()->ready()->create();

        $this->assertSame(TrackStatus::Ready, $track->refresh()->status);
        $this->assertTrue($track->status->isReleasable());
    }

    private function releaseWithArtistAndTrack(?Asset $cover, ?Track $track = null): Release
    {
        $release = Release::factory()->create([
            'cover_asset_id' => $cover?->id,
        ]);

        $release->artists()->attach(Artist::factory()->create()->id, [
            'role' => ReleaseArtistRole::Primary->value, 'position' => 0,
        ]);

        $release->tracks()->attach(($track ?? Track::factory()->ready()->create())->id, [
            'disc_number' => 1, 'track_number' => 1,
        ]);

        return $release->refresh();
    }
}
