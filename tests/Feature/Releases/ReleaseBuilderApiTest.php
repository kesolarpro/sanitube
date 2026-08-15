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
use SaniTube\Localization\ContentLanguage;
use SaniTube\Releases\Enums\ReleaseArtistRole;
use SaniTube\Releases\Enums\ReleaseStatus;
use SaniTube\Releases\Enums\ReleaseType;
use SaniTube\Releases\Models\Release;
use SaniTube\Releases\Services\ReleaseBuilder;
use Tests\TestCase;

/**
 * The Release Builder over HTTP.
 *
 * The catalogue's read-only boundary is widened here for the first time beyond
 * candidate promotion, and it is widened narrowly: these routes assemble a
 * *package* of existing recordings. None of them edits a Track, and none sets
 * a status directly — readiness is earned by passing validation.
 */
final class ReleaseBuilderApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'a-long-internal-api-token-for-testing';

    protected function setUp(): void
    {
        parent::setUp();

        config(['sanitube.api.internal_token' => self::TOKEN]);
    }

    #[Test]
    public function the_builder_endpoints_refuse_a_caller_without_the_token(): void
    {
        $release = $this->builder()->createDraft('Night Bus');

        $this->postJson('/api/v1/releases', ['title' => 'x'])->assertUnauthorized();
        $this->patchJson('/api/v1/releases/'.$release->uuid, ['title' => 'x'])->assertUnauthorized();
        $this->postJson('/api/v1/releases/'.$release->uuid.'/ready')->assertUnauthorized();
    }

    #[Test]
    public function a_release_is_created_as_a_draft(): void
    {
        $this->authenticated()->postJson('/api/v1/releases', [
            'title' => 'Night Bus',
            'type' => 'EP',
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Night Bus')
            ->assertJsonPath('data.status', 'DRAFT');
    }

    #[Test]
    public function tracks_can_be_added_removed_and_reordered_over_http(): void
    {
        $release = $this->builder()->createDraft('Album', ReleaseType::Album);
        $one = $this->track('One');
        $two = $this->track('Two');
        $three = $this->track('Three');

        foreach ([$one, $two, $three] as $track) {
            $this->authenticated()
                ->postJson('/api/v1/releases/'.$release->uuid.'/tracks', ['track' => $track->uuid])
                ->assertOk();
        }

        $this->authenticated()->postJson('/api/v1/releases/'.$release->uuid.'/tracks/reorder', [
            'tracks' => [$three->uuid, $one->uuid, $two->uuid],
        ])->assertOk();

        $this->assertSame(['Three', 'One', 'Two'], $release->refresh()->tracks()->get()->pluck('title')->all());

        $this->authenticated()
            ->deleteJson('/api/v1/releases/'.$release->uuid.'/tracks/'.$one->uuid)
            ->assertOk();

        // The gap closes: a tracklist running 1, 3 is rejected on delivery.
        $this->assertSame(
            [1, 2],
            $release->refresh()->trackEntries()->orderBy('track_number')->pluck('track_number')->map(intval(...))->all(),
        );
    }

    #[Test]
    public function adding_the_same_track_twice_is_refused_with_a_reason(): void
    {
        $release = $this->builder()->createDraft('Single');
        $track = $this->track('One');

        $this->authenticated()->postJson('/api/v1/releases/'.$release->uuid.'/tracks', ['track' => $track->uuid])
            ->assertOk();

        $this->authenticated()->postJson('/api/v1/releases/'.$release->uuid.'/tracks', ['track' => $track->uuid])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'TRACK_ALREADY_ON_RELEASE');
    }

    #[Test]
    public function validate_reports_errors_and_warnings_without_changing_anything(): void
    {
        // A label wants to see what is missing while it is still assembling.
        $release = $this->builder()->createDraft('Single');

        $this->authenticated()->postJson('/api/v1/releases/'.$release->uuid.'/validate')
            ->assertOk()
            ->assertJsonPath('data.valid', false)
            ->assertJsonStructure(['data' => ['valid', 'errors', 'warnings']]);

        $this->assertSame(ReleaseStatus::Draft, $release->refresh()->status);
    }

    #[Test]
    public function ready_is_refused_while_anything_blocks_it_and_says_what(): void
    {
        $release = $this->builder()->createDraft('Single');

        $this->authenticated()->postJson('/api/v1/releases/'.$release->uuid.'/ready')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'RELEASE_NOT_READY')
            ->assertJsonPath('data.valid', false);

        $this->assertSame(ReleaseStatus::Draft, $release->refresh()->status);
    }

    #[Test]
    public function a_complete_release_can_be_marked_ready_and_then_refuses_edits(): void
    {
        $release = $this->completeDraft();

        $this->authenticated()->postJson('/api/v1/releases/'.$release->uuid.'/ready')
            ->assertOk()
            ->assertJsonPath('data.status', 'READY');

        $this->authenticated()->postJson('/api/v1/releases/'.$release->uuid.'/tracks', [
            'track' => $this->track('Late')->uuid,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'RELEASE_NOT_EDITABLE');
    }

    #[Test]
    public function reopening_a_ready_release_makes_it_editable_again(): void
    {
        $release = $this->completeDraft();
        $release->markReady();

        $this->authenticated()->postJson('/api/v1/releases/'.$release->uuid.'/reopen')
            ->assertOk()
            ->assertJsonPath('data.status', 'DRAFT');

        $this->authenticated()->patchJson('/api/v1/releases/'.$release->uuid, ['label_name' => 'A Label'])
            ->assertOk();
    }

    #[Test]
    public function a_committed_release_cannot_be_reopened(): void
    {
        $release = $this->completeDraft();
        $release->forceFill(['status' => ReleaseStatus::Live])->save();

        $this->authenticated()->postJson('/api/v1/releases/'.$release->uuid.'/reopen')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'RELEASE_NOT_REOPENABLE');
    }

    #[Test]
    public function a_patch_cannot_assign_a_status(): void
    {
        // Readiness is earned by passing validation, not assigned. A settable
        // status field would make I4 optional.
        $release = $this->builder()->createDraft('Single');

        $this->authenticated()->patchJson('/api/v1/releases/'.$release->uuid, [
            'title' => 'Renamed',
            'status' => 'LIVE',
        ])->assertOk();

        $this->assertSame(ReleaseStatus::Draft, $release->refresh()->status);
        $this->assertSame('Renamed', $release->title);
    }

    // --------------------------------------------------------------- helpers

    private function authenticated(): self
    {
        return $this->withHeader((string) config('sanitube.api.token_header', 'X-SaniTube-Token'), self::TOKEN);
    }

    private function builder(): ReleaseBuilder
    {
        return $this->app->make(ReleaseBuilder::class);
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

    private function completeDraft(): Release
    {
        $release = $this->builder()->createDraft('Night Bus');
        $release = $this->builder()->addTrack($release, $this->track('One'));

        $this->builder()->setArtists($release->refresh(), [
            ['artist' => Artist::factory()->create(), 'role' => ReleaseArtistRole::Primary],
        ]);

        $bytes = 'artwork bytes';

        $this->builder()->setCover($release->refresh(), Asset::query()->create([
            'kind' => AssetKind::Artwork,
            'status' => AssetStatus::Verified,
            'disk' => 'memory',
            'path' => 'artwork/cover.jpg',
            'original_filename' => 'cover.jpg',
            'sha256' => hash('sha256', $bytes),
            'byte_size' => strlen($bytes),
            'mime_type' => 'image/jpeg',
        ]));

        return $this->builder()->updateDetails($release->refresh(), [
            'release_date' => now()->addMonth()->toDateString(),
        ]);
    }
}
