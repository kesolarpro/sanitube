<?php

declare(strict_types=1);

namespace Tests\Feature\Releases;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Artists\Models\Artist;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Catalog\Enums\TrackStatus;
use SaniTube\Catalog\Models\Track;
use SaniTube\Foundation\Validation\ValidationIssue;
use SaniTube\Localization\ContentLanguage;
use SaniTube\Releases\Enums\ReleaseArtistRole;
use SaniTube\Releases\Enums\ReleaseStatus;
use SaniTube\Releases\Enums\ReleaseType;
use SaniTube\Releases\Exceptions\ReleaseBuilderException;
use SaniTube\Releases\Exceptions\ReleaseNotReadyException;
use SaniTube\Releases\Models\Release;
use SaniTube\Releases\Services\ReleaseBuilder;
use SaniTube\Releases\Services\ValidateRelease;
use Tests\TestCase;

/**
 * Assembling a release, and the point at which it stops being editable.
 *
 * ARCH-002 modelled what a release *is* and enforces I4. This is how one gets
 * built, and most of what follows is about two things: keeping the tracklist
 * numbered in a way a store will accept, and refusing to change a package
 * somebody has already been given.
 */
final class ReleaseBuilderTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------- building

    #[Test]
    public function a_new_release_is_a_draft_with_an_undetermined_language(): void
    {
        // `und` rather than a guess: a wrong language code reaches DSPs.
        $release = $this->builder()->createDraft('Night Bus');

        $this->assertSame(ReleaseStatus::Draft, $release->status);
        $this->assertSame(ContentLanguage::UNKNOWN, $release->language_code);
        $this->assertSame(ReleaseType::Single, $release->type);
    }

    #[Test]
    public function tracks_are_numbered_by_the_builder_rather_than_by_the_caller(): void
    {
        // A caller that picked its own numbers would eventually pick one that
        // already exists, and two number 4s is a delivery a store rejects.
        $release = $this->builder()->createDraft('EP', ReleaseType::Ep);

        foreach (['One', 'Two', 'Three'] as $title) {
            $this->builder()->addTrack($release, $this->track($title));
        }

        $this->assertSame([1, 2, 3], $this->numbers($release));
    }

    #[Test]
    public function the_same_track_cannot_be_added_twice(): void
    {
        $release = $this->builder()->createDraft('Single');
        $track = $this->track('One');

        $this->builder()->addTrack($release, $track);

        try {
            $this->builder()->addTrack($release->refresh(), $track);
            $this->fail('A duplicate track must be refused.');
        } catch (ReleaseBuilderException $exception) {
            $this->assertSame('TRACK_ALREADY_ON_RELEASE', $exception->reason);
        }
    }

    #[Test]
    public function removing_a_track_closes_the_gap_it_leaves(): void
    {
        // A tracklist running 1, 2, 4 is rejected on delivery, and leaving the
        // gap until someone happens to reorder means the release is invalid in
        // a way nobody looked at.
        $release = $this->builder()->createDraft('Album', ReleaseType::Album);
        $tracks = [];

        foreach (['One', 'Two', 'Three', 'Four'] as $title) {
            $tracks[$title] = $this->track($title);
            $this->builder()->addTrack($release, $tracks[$title]);
        }

        $this->builder()->removeTrack($release->refresh(), $tracks['Two']);

        $this->assertSame([1, 2, 3], $this->numbers($release));
        $this->assertSame(['One', 'Three', 'Four'], $this->titles($release));
    }

    #[Test]
    public function a_track_that_is_not_on_the_release_cannot_be_removed(): void
    {
        $release = $this->builder()->createDraft('Single');

        try {
            $this->builder()->removeTrack($release, $this->track('Elsewhere'));
            $this->fail('Removing a track that is not there must be refused.');
        } catch (ReleaseBuilderException $exception) {
            $this->assertSame('TRACK_NOT_ON_RELEASE', $exception->reason);
        }
    }

    #[Test]
    public function reordering_renumbers_continuously_from_one(): void
    {
        $release = $this->builder()->createDraft('Album', ReleaseType::Album);
        $tracks = [];

        foreach (['One', 'Two', 'Three'] as $title) {
            $tracks[$title] = $this->track($title);
            $this->builder()->addTrack($release, $tracks[$title]);
        }

        $this->builder()->reorder($release->refresh(), [
            $tracks['Three']->uuid,
            $tracks['One']->uuid,
            $tracks['Two']->uuid,
        ]);

        $this->assertSame(['Three', 'One', 'Two'], $this->titles($release));
        $this->assertSame([1, 2, 3], $this->numbers($release));
    }

    #[Test]
    public function a_partial_reorder_keeps_the_tracks_it_did_not_mention(): void
    {
        // Dropping them would make reorder a destructive operation that looks
        // like a cosmetic one.
        $release = $this->builder()->createDraft('Album', ReleaseType::Album);
        $tracks = [];

        foreach (['One', 'Two', 'Three'] as $title) {
            $tracks[$title] = $this->track($title);
            $this->builder()->addTrack($release, $tracks[$title]);
        }

        $this->builder()->reorder($release->refresh(), [$tracks['Three']->uuid]);

        $this->assertSame(3, $release->refresh()->tracks()->count());
        $this->assertSame('Three', $this->titles($release)[0]);
        $this->assertSame([1, 2, 3], $this->numbers($release));
    }

    #[Test]
    public function a_multi_disc_release_numbers_each_disc_from_one(): void
    {
        $release = $this->builder()->createDraft('Compilation', ReleaseType::Compilation);

        $this->builder()->addTrack($release, $this->track('A1'), disc: 1);
        $this->builder()->addTrack($release->refresh(), $this->track('A2'), disc: 1);
        $this->builder()->addTrack($release->refresh(), $this->track('B1'), disc: 2);

        $entries = $release->refresh()->trackEntries()->get();

        $this->assertSame([1, 2], $entries->where('disc_number', 1)->pluck('track_number')->all());
        $this->assertSame([1], $entries->where('disc_number', 2)->pluck('track_number')->values()->all());
    }

    // -------------------------------------------------------------- artists

    #[Test]
    public function a_release_can_carry_several_primary_artists(): void
    {
        // `release_artist` is the only source of truth for credits, and a
        // collaboration has two names on the front.
        $release = $this->builder()->createDraft('Split');

        $this->builder()->setArtists($release, [
            ['artist' => $this->artist('One'), 'role' => ReleaseArtistRole::Primary],
            ['artist' => $this->artist('Two'), 'role' => ReleaseArtistRole::Primary],
        ]);

        $this->assertSame(2, $release->refresh()->primaryArtists()->count());
    }

    #[Test]
    public function credits_with_no_primary_artist_are_refused(): void
    {
        $release = $this->builder()->createDraft('Single');

        try {
            $this->builder()->setArtists($release, [
                ['artist' => $this->artist('Feature'), 'role' => ReleaseArtistRole::Featured],
            ]);
            $this->fail('A release with no primary artist must be refused.');
        } catch (ReleaseBuilderException $exception) {
            $this->assertSame('PRIMARY_ARTIST_REQUIRED', $exception->reason);
        }
    }

    // ---------------------------------------------------------------- cover

    #[Test]
    public function a_verified_artwork_asset_can_be_the_cover(): void
    {
        $release = $this->builder()->createDraft('Single');

        $this->builder()->setCover($release, $this->cover());

        $this->assertNotNull($release->refresh()->cover_asset_id);
    }

    #[Test]
    public function an_unverified_cover_is_refused_when_it_is_set(): void
    {
        // Caught when the mistake is made, not at the end when somebody is
        // trying to deliver.
        $release = $this->builder()->createDraft('Single');
        $cover = $this->cover(AssetStatus::Pending);

        try {
            $this->builder()->setCover($release, $cover);
            $this->fail('An unverified cover must be refused.');
        } catch (ReleaseBuilderException $exception) {
            $this->assertSame('COVER_NOT_USABLE', $exception->reason);
        }
    }

    #[Test]
    public function an_audio_asset_cannot_be_used_as_a_cover(): void
    {
        $release = $this->builder()->createDraft('Single');

        $bytes = 'audio bytes';

        $audio = Asset::query()->create([
            'kind' => AssetKind::AudioMaster,
            'status' => AssetStatus::Verified,
            'disk' => 'memory',
            'path' => 'masters/x.wav',
            'original_filename' => 'x.wav',
            'sha256' => hash('sha256', $bytes),
            'byte_size' => strlen($bytes),
            'mime_type' => 'audio/wav',
        ]);

        $this->expectException(ReleaseBuilderException::class);

        $this->builder()->setCover($release, $audio);
    }

    // ---------------------------------------------------- the mutation gate

    #[Test]
    public function a_ready_release_refuses_structural_change_until_it_is_reopened(): void
    {
        // READY is a statement that the package passed validation. Adding a
        // track afterwards would leave a release marked ready that never was.
        $release = $this->readyRelease();

        try {
            $this->builder()->addTrack($release, $this->track('Late addition'));
            $this->fail('A READY release must not accept structural change.');
        } catch (ReleaseBuilderException $exception) {
            $this->assertSame('RELEASE_NOT_EDITABLE', $exception->reason);
        }

        $reopened = $this->builder()->reopen($release->refresh());
        $this->assertSame(ReleaseStatus::Draft, $reopened->status);

        // And now it is editable again.
        $this->builder()->addTrack($reopened, $this->track('Late addition'));
        $this->assertSame(2, $reopened->refresh()->tracks()->count());
    }

    #[Test]
    public function a_committed_release_cannot_be_edited_or_reopened(): void
    {
        // The recordings are outside SaniTube now: a store is serving them and
        // a royalty statement will reference them.
        $release = $this->readyRelease();
        $release->forceFill(['status' => ReleaseStatus::Live])->save();

        try {
            $this->builder()->updateDetails($release, ['title' => 'Renamed']);
            $this->fail('A live release must not be editable.');
        } catch (ReleaseBuilderException $exception) {
            $this->assertSame('RELEASE_NOT_EDITABLE', $exception->reason);
        }

        try {
            $this->builder()->reopen($release);
            $this->fail('A live release must not be reopenable.');
        } catch (ReleaseBuilderException $exception) {
            $this->assertSame('RELEASE_NOT_REOPENABLE', $exception->reason);
        }
    }

    // ----------------------------------------------------------- validation

    #[Test]
    public function validation_separates_what_blocks_delivery_from_what_does_not(): void
    {
        // A validator that reported a missing catalogue number with the same
        // weight as a missing cover trains its reader to ignore the list.
        $release = $this->builder()->createDraft('Single');

        $result = $this->validator()->handle($release);

        $this->assertFalse($result->isValid());
        $this->assertNotSame([], $result->errors());
        $this->assertNotSame([], $result->warnings());

        // REL-002: asserted on codes rather than on English. The old version
        // of this test would have gone green on a reworded sentence and red on
        // a translated one, which is the wrong way round for both.
        $this->assertTrue($result->has('COVER_REQUIRED'));
        $this->assertTrue($result->has('NO_CATALOGUE_NUMBER'));
    }

    #[Test]
    public function a_complete_release_validates_with_warnings_but_no_errors(): void
    {
        $result = $this->validator()->handle($this->readyRelease());

        $this->assertTrue($result->isValid(), implode('; ', $result->messages()));
    }

    #[Test]
    public function a_draft_track_blocks_the_release(): void
    {
        $release = $this->completeDraft();
        $release->tracks()->first()?->forceFill(['status' => TrackStatus::Draft])->save();

        $result = $this->validator()->handle($release->refresh());

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->has('TRACK_NOT_RELEASABLE'));
    }

    #[Test]
    public function a_gap_in_the_numbering_is_an_error(): void
    {
        // Reached by writing the pivot directly, because the builder will not
        // produce a gap — which is the point of the builder.
        $release = $this->completeDraft();
        $entry = $release->trackEntries()->firstOrFail();
        $release->tracks()->updateExistingPivot($entry->track_id, ['track_number' => 4]);

        $result = $this->validator()->handle($release->refresh());

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->has('DISC_NUMBERING_NOT_CONTIGUOUS'));
    }

    #[Test]
    public function a_multi_track_release_without_a_focus_track_is_only_warned_about(): void
    {
        $release = $this->completeDraft(tracks: 2);

        $result = $this->validator()->handle($release);

        $this->assertTrue($result->isValid(), implode('; ', $result->messages()));
        $this->assertTrue($result->has('NO_FOCUS_TRACK'));
    }

    #[Test]
    public function a_release_with_no_date_is_blocked_rather_than_merely_warned_about(): void
    {
        // Severity is what decides whether a release may be delivered, so it is
        // asserted here rather than inferred from the issue being present at
        // all: downgrading this one to a warning would leave the list looking
        // identical while letting a dateless release through validation, and a
        // store given a release with no date has nothing to schedule.
        $release = $this->completeDraft();
        $release->forceFill(['release_date' => null])->save();

        $result = $this->validator()->handle($release->refresh());

        $this->assertFalse($result->isValid(), 'A release with no date must not validate.');
        $this->assertContains('RELEASE_DATE_REQUIRED', array_map(
            static fn (ValidationIssue $issue): string => $issue->code,
            $result->errors(),
        ));
    }

    #[Test]
    public function marking_ready_is_refused_while_anything_blocks_it(): void
    {
        $release = $this->builder()->createDraft('Single');

        $this->assertFalse($this->validator()->handle($release)->isValid());

        try {
            $release->markReady();
            $this->fail('An incomplete release was promoted to READY.');
        } catch (ReleaseNotReadyException $e) {
            // The refusal names what is wrong, so a caller can act on it
            // rather than only learn that something was.
            $this->assertContains('COVER_REQUIRED', array_map(
                static fn (ValidationIssue $issue): string => $issue->code,
                $e->problems,
            ));
        }

        $this->assertSame(ReleaseStatus::Draft, $release->refresh()->status);
    }

    #[Test]
    public function a_single_an_ep_and_an_album_all_build_the_same_way(): void
    {
        foreach ([[ReleaseType::Single, 1], [ReleaseType::Ep, 4], [ReleaseType::Album, 10]] as [$type, $count]) {
            $release = $this->completeDraft(tracks: $count, type: $type);

            $this->assertTrue(
                $this->validator()->handle($release)->isValid(),
                sprintf('%s should validate.', $type->value),
            );
            $this->assertSame(range(1, $count), $this->numbers($release));
        }
    }

    // --------------------------------------------------------------- helpers

    private function builder(): ReleaseBuilder
    {
        return $this->app->make(ReleaseBuilder::class);
    }

    private function validator(): ValidateRelease
    {
        return $this->app->make(ValidateRelease::class);
    }

    private function track(string $title): Track
    {
        return Track::factory()->create([
            'title' => $title,
            'status' => TrackStatus::Ready,
            'language_code' => ContentLanguage::UNKNOWN,
            'is_instrumental' => false,
        ]);
    }

    private function artist(string $name): Artist
    {
        return Artist::factory()->create(['name' => $name]);
    }

    /**
     * A cover that satisfies I10.
     *
     * AST-001 refuses a VERIFIED asset with no recorded bytes — "a status the
     * platform treats as proof must not rest on unrecorded bytes" — so these
     * are real values rather than an invariant worked around.
     */
    private function cover(AssetStatus $status = AssetStatus::Verified): Asset
    {
        $bytes = 'artwork bytes';

        return Asset::query()->create([
            'kind' => AssetKind::Artwork,
            'status' => $status,
            'disk' => 'memory',
            'path' => 'artwork/cover-'.bin2hex(random_bytes(4)).'.jpg',
            'original_filename' => 'cover.jpg',
            'sha256' => hash('sha256', $bytes),
            'byte_size' => strlen($bytes),
            'mime_type' => 'image/jpeg',
        ]);
    }

    private function completeDraft(int $tracks = 1, ReleaseType $type = ReleaseType::Single): Release
    {
        $release = $this->builder()->createDraft('Night Bus', $type);

        foreach (range(1, $tracks) as $index) {
            $release = $this->builder()->addTrack($release->refresh(), $this->track('Track '.$index));
        }

        $this->builder()->setArtists($release->refresh(), [
            ['artist' => $this->artist('The Label'), 'role' => ReleaseArtistRole::Primary],
        ]);

        $this->builder()->setCover($release->refresh(), $this->cover());

        return $this->builder()->updateDetails($release->refresh(), [
            'release_date' => now()->addMonth()->toDateString(),
        ]);
    }

    private function readyRelease(): Release
    {
        $release = $this->completeDraft();
        $release->markReady();

        return $release->refresh();
    }

    /**
     * @return list<int>
     */
    private function numbers(Release $release): array
    {
        return $release->refresh()->trackEntries()
            ->orderBy('disc_number')->orderBy('track_number')
            ->pluck('track_number')->map(intval(...))->all();
    }

    /**
     * @return list<string>
     */
    private function titles(Release $release): array
    {
        return $release->refresh()->tracks()->get()->pluck('title')->all();
    }
}
