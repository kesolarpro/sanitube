<?php

declare(strict_types=1);

namespace Tests\Feature\Artwork;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Artwork\ArtworkMeasurement;
use SaniTube\Artwork\Contracts\ImageMeasurer;
use SaniTube\Artwork\Jobs\MeasureArtworkJob;
use SaniTube\Artwork\Measurers\NativeImageMeasurer;
use SaniTube\Artwork\Models\ArtworkAnalysis;
use SaniTube\Artwork\Services\MeasureArtwork;
use SaniTube\Artwork\Services\MeasuredDimensions;
use SaniTube\Artwork\Services\ValidateArtwork;
use SaniTube\Artwork\Testing\FakeImageMeasurer;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Releases\Models\Release;
use SaniTube\Releases\Services\ValidateRelease;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use SaniTube\Ui\Queries\AssetDetailQuery;
use SaniTube\Ui\Queries\ReleaseDetailQuery;
use SaniTube\Ui\Queries\ReleasePickerQuery;
use Tests\TestCase;

/**
 * ART-001 — artwork is measured, and judged on what was measured.
 *
 * Before this ticket a cover was validated on two things: that its asset record
 * said `ARTWORK`, and that its checksum had verified. Both are properties of the
 * *record*. A cover could satisfy both and be a 600×600 CMYK JPEG that no store
 * accepts, and nothing in the platform would have said so.
 *
 * **The load-bearing distinction is unmeasured versus conforming.** An
 * installation that cannot measure images, or a cover verified before this
 * module existed, must report "nobody has looked" — never a pass. Silence is
 * how a release is delivered with artwork that had simply never been checked.
 */
final class ArtworkValidationTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryStorageProvider $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new InMemoryStorageProvider('memory');
        config(['storage.default' => 'memory']);
        $this->app->make(StorageManager::class)->register('memory', $this->store);

        Queue::fake();
    }

    // ------------------------------------------------------ measuring

    #[Test]
    public function a_real_image_is_measured_from_its_bytes(): void
    {
        // A genuine PNG, written here rather than described. The whole point of
        // this module is that a dimension comes from the file.
        $asset = $this->storedArtwork($this->png(1400, 1400));

        $analysis = $this->app->make(MeasureArtwork::class)->handle($asset);

        $this->assertInstanceOf(ArtworkAnalysis::class, $analysis);
        $this->assertTrue($analysis->succeeded);
        $this->assertSame(1400, $analysis->width_px);
        $this->assertSame(1400, $analysis->height_px);
        $this->assertSame('image/png', $analysis->mime_type);
        $this->assertGreaterThan(0, (int) $analysis->bytes);
    }

    #[Test]
    public function a_file_that_is_not_an_image_is_recorded_as_a_failed_measurement(): void
    {
        // Recorded, not skipped. A release validator has to tell "nobody has
        // looked at this cover" from "somebody looked and it is not an image":
        // the first is a warning to go and look, the second is an error about
        // the file. Skipping would collapse both into the first.
        $asset = $this->storedArtwork('this is not a png');

        $analysis = $this->app->make(MeasureArtwork::class)->handle($asset);

        $this->assertInstanceOf(ArtworkAnalysis::class, $analysis);
        $this->assertFalse($analysis->succeeded);
        $this->assertNull($analysis->width_px);
        $this->assertNull($analysis->measurement());
    }

    #[Test]
    public function nothing_but_verified_artwork_is_measured(): void
    {
        // Built as what they are, not mutated into it: a verified asset is
        // immutable by design, and forcing a kind onto one would be testing a
        // state the platform refuses to produce.
        $master = Asset::factory()->verified()->create(['disk' => 'memory']);
        $this->store->put($master->path, $this->png(100, 100));

        $this->assertNull($this->app->make(MeasureArtwork::class)->handle($master));

        $unverified = Asset::factory()->artwork()->create(['disk' => 'memory', 'status' => AssetStatus::Stored]);
        $this->store->put($unverified->path, $this->png(100, 100));

        // Measuring bytes nobody has confirmed would describe an object that
        // may not be the one the catalogue means.
        $this->assertNull($this->app->make(MeasureArtwork::class)->handle($unverified));

        $this->assertSame(0, ArtworkAnalysis::query()->count());
    }

    #[Test]
    public function an_installation_that_cannot_measure_records_nothing_at_all(): void
    {
        // Not a failed measurement. A row saying "failed" would be
        // indistinguishable from a corrupt file and would still be there long
        // after the capability arrived.
        $this->app->instance(ImageMeasurer::class, new FakeImageMeasurer(available: false));

        $asset = $this->storedArtwork($this->png(3000, 3000));

        $this->assertNull($this->app->make(MeasureArtwork::class)->handle($asset));
        $this->assertSame(0, ArtworkAnalysis::query()->count());
    }

    #[Test]
    public function measuring_is_idempotent_per_version_and_a_new_version_keeps_the_old_answer(): void
    {
        $asset = $this->storedArtwork($this->png(1000, 1000));

        $measurer = new FakeImageMeasurer(new ArtworkMeasurement(1000, 1000, 'image/png', 10));
        $this->app->instance(ImageMeasurer::class, $measurer);

        $this->app->make(MeasureArtwork::class)->handle($asset);
        $this->app->make(MeasureArtwork::class)->handle($asset);

        $this->assertSame(1, ArtworkAnalysis::query()->count());

        // A better measurer adds a row beside the old one, so what an earlier
        // version concluded stays readable.
        $measurer->atVersion('2')->reports(new ArtworkMeasurement(1000, 1000, 'image/png', 10, channels: 3));
        $this->app->make(MeasureArtwork::class)->handle($asset);

        $this->assertSame(2, ArtworkAnalysis::query()->count());
    }

    // ----------------------------------------------------- validating

    #[Test]
    public function conforming_artwork_raises_nothing(): void
    {
        $asset = $this->measured(new ArtworkMeasurement(3000, 3000, 'image/jpeg', 900_000, channels: 3));

        $this->assertSame([], $this->codes($asset));
    }

    #[Test]
    public function a_minimum_is_about_the_shortest_side_not_the_width(): void
    {
        // 4000 wide satisfies "at least 3000" and is not usable as a cover.
        // This is the case a naive width check gets wrong.
        config(['artwork.requirements.must_be_square' => false]);

        $asset = $this->measured(new ArtworkMeasurement(4000, 1000, 'image/jpeg', 10, channels: 3));

        $this->assertContains('COVER_TOO_SMALL', $this->codes($asset));
    }

    #[Test]
    public function a_cover_that_is_not_square_is_refused(): void
    {
        $asset = $this->measured(new ArtworkMeasurement(3000, 2400, 'image/jpeg', 10, channels: 3));

        $this->assertContains('COVER_NOT_SQUARE', $this->codes($asset));
    }

    #[Test]
    public function a_format_nobody_accepts_is_refused_by_its_measured_type(): void
    {
        // Measured, never the extension. A .jpg holding a TIFF is a real thing
        // and a store reads the bytes, so this platform does too.
        $asset = $this->measured(new ArtworkMeasurement(3000, 3000, 'image/tiff', 10));

        $this->assertContains('COVER_FORMAT_NOT_ACCEPTED', $this->codes($asset));
    }

    #[Test]
    public function a_cmyk_cover_is_refused_and_an_unknown_colour_space_is_not(): void
    {
        $cmyk = $this->measured(new ArtworkMeasurement(3000, 3000, 'image/jpeg', 10, channels: 4));
        $this->assertContains('COVER_IS_CMYK', $this->codes($cmyk));

        // A PNG records no channel count. Null is not "no" -- but neither is it
        // grounds to warn, because warning on every PNG would train a reader to
        // ignore the list.
        $unknown = $this->measured(new ArtworkMeasurement(3000, 3000, 'image/png', 10));
        $this->assertSame([], $this->codes($unknown));
    }

    #[Test]
    public function an_unmeasured_cover_is_a_warning_and_never_a_pass(): void
    {
        // The load-bearing case. Nobody has looked, and reporting that as
        // conforming is how a 600x600 cover reaches a store.
        $asset = $this->storedArtwork($this->png(600, 600));

        $issues = $this->app->make(ValidateArtwork::class)->handle($asset);

        $this->assertCount(1, $issues);
        $this->assertSame('COVER_NOT_MEASURED', $issues[0]->code);
        $this->assertFalse($issues[0]->isError());
    }

    #[Test]
    public function a_cover_nobody_could_read_is_an_error_not_a_warning(): void
    {
        $asset = $this->storedArtwork('not an image at all');

        $this->app->make(MeasureArtwork::class)->handle($asset);

        $issues = $this->app->make(ValidateArtwork::class)->handle($asset);

        $this->assertCount(1, $issues);
        $this->assertSame('COVER_UNREADABLE', $issues[0]->code);
        $this->assertTrue($issues[0]->isError());
    }

    #[Test]
    public function every_requirement_can_be_switched_off(): void
    {
        // A rule nobody wants should be switched off rather than worked around,
        // and "no requirement" has to be honoured everywhere or an operator
        // delivering somewhere with different rules has to patch a validator.
        config([
            'artwork.requirements.minimum_pixels' => 0,
            'artwork.requirements.maximum_pixels' => 0,
            'artwork.requirements.maximum_bytes' => 0,
            'artwork.requirements.must_be_square' => false,
            'artwork.requirements.accepted_mime_types' => [],
            'artwork.requirements.refuse_cmyk' => false,
        ]);

        $asset = $this->measured(new ArtworkMeasurement(40, 12, 'image/tiff', 900_000_000, channels: 4));

        $this->assertSame([], $this->codes($asset));
    }

    #[Test]
    public function an_upper_bound_is_applied_when_one_is_configured(): void
    {
        config(['artwork.requirements.maximum_pixels' => 5000, 'artwork.requirements.maximum_bytes' => 1000]);

        $asset = $this->measured(new ArtworkMeasurement(6000, 6000, 'image/jpeg', 2000, channels: 3));
        $codes = $this->codes($asset);

        $this->assertContains('COVER_TOO_LARGE', $codes);
        $this->assertContains('COVER_FILE_TOO_LARGE', $codes);
    }

    // ------------------------------------------------- production path

    #[Test]
    public function verifying_artwork_queues_a_measurement_and_verifying_a_master_does_not(): void
    {
        // WHO CALLS THIS IN PRODUCTION: the observer emits AssetVerified when
        // an asset reaches VERIFIED, and the listener picks it up. Nothing in
        // the asset pipeline knows this module exists — so no event is
        // dispatched by hand here. Reaching the verified state *is* the trigger,
        // and a test that dispatched the event itself would prove the listener
        // works without proving anything ever calls it.
        $artwork = $this->storedArtwork($this->png(100, 100));

        Queue::assertPushed(
            MeasureArtworkJob::class,
            static fn (MeasureArtworkJob $job): bool => $job->assetUuid === $artwork->uuid,
        );
        Queue::assertPushed(MeasureArtworkJob::class, 1);

        Asset::factory()->verified()->create(['disk' => 'memory']);

        // Still one. Queueing a job for every master in a 900-file import so it
        // can decline is a queue nobody needed.
        Queue::assertPushed(MeasureArtworkJob::class, 1);
    }

    #[Test]
    public function the_job_measures_the_asset_it_names(): void
    {
        $asset = $this->storedArtwork($this->png(2200, 2200));

        (new MeasureArtworkJob($asset->uuid))->handle($this->app->make(MeasureArtwork::class));

        $analysis = ArtworkAnalysis::query()->where('asset_id', $asset->id)->first();

        $this->assertInstanceOf(ArtworkAnalysis::class, $analysis);
        $this->assertSame(2200, $analysis->width_px);
    }

    #[Test]
    public function a_screen_reports_the_measured_size_and_null_when_nobody_measured(): void
    {
        // The bug this found: `assets.width` and `assets.height` are columns
        // nothing in this platform has ever written. Three screens read them,
        // so in production they were always blank -- and the suite did not
        // notice because the asset factory sets 3000x3000, making every test
        // look at a perfectly-sized cover that never existed.
        $asset = $this->storedArtwork($this->png(1234, 1234));

        $dimensions = $this->app->make(MeasuredDimensions::class);

        $this->assertSame(['width' => null, 'height' => null], $dimensions->for($asset));

        $this->app->make(MeasureArtwork::class)->handle($asset);

        $this->assertSame(['width' => 1234, 'height' => 1234], $dimensions->for($asset->refresh()));
    }

    #[Test]
    public function release_validation_carries_the_artwork_findings(): void
    {
        // WHO ELSE CALLS THIS IN PRODUCTION: ValidateRelease, which is what the
        // release screen and the readiness gate both run. A validator nothing
        // consults is a validator that never stops a delivery.
        $cover = $this->measured(new ArtworkMeasurement(600, 400, 'image/tiff', 10, channels: 4));
        $release = Release::factory()->create(['cover_asset_id' => $cover->id]);

        $codes = array_map(
            static fn ($issue): string => $issue->code,
            $this->app->make(ValidateRelease::class)->handle($release)->issues,
        );

        $this->assertContains('COVER_NOT_SQUARE', $codes);
        $this->assertContains('COVER_TOO_SMALL', $codes);
        $this->assertContains('COVER_FORMAT_NOT_ACCEPTED', $codes);
        $this->assertContains('COVER_IS_CMYK', $codes);
    }

    #[Test]
    public function a_release_whose_cover_nobody_measured_is_warned_about_not_passed(): void
    {
        $cover = $this->storedArtwork($this->png(600, 600));
        $release = Release::factory()->create(['cover_asset_id' => $cover->id]);

        $result = $this->app->make(ValidateRelease::class)->handle($release);

        $codes = array_map(static fn ($issue): string => $issue->code, $result->issues);

        // Present, and not an error: an installation that cannot measure must
        // not have every release reported as broken.
        $this->assertContains('COVER_NOT_MEASURED', $codes);

        foreach ($result->issues as $issue) {
            if ($issue->code === 'COVER_NOT_MEASURED') {
                $this->assertFalse($issue->isError());
            }
        }
    }

    #[Test]
    public function the_release_screen_shows_the_measured_size_and_never_the_dead_column(): void
    {
        // The sharpest form of the bug. `Asset::factory()->artwork()` writes
        // width=3000 into `assets.width` — a column nothing in this platform
        // populates — so a screen reading it showed 3000 for a cover nobody had
        // looked at, on a test that then passed. Here the same fixture must
        // report null until something measures it, and 1234 afterwards.
        $cover = $this->storedArtwork($this->png(1234, 1234));
        $release = Release::factory()->create(['cover_asset_id' => $cover->id]);

        $this->assertSame(3000, $cover->width, 'The fixture must carry the misleading value for this to prove anything.');

        $viewer = User::factory()->create(['role' => UserRole::Owner, 'is_active' => true]);

        $before = $this->app->make(ReleaseDetailQuery::class)->forRelease($release, $viewer);
        $this->assertNull($before['cover']['width']);

        $this->app->make(MeasureArtwork::class)->handle($cover);

        $after = $this->app->make(ReleaseDetailQuery::class)->forRelease($release->refresh(), $viewer);
        $this->assertSame(1234, $after['cover']['width']);
        $this->assertSame(1234, $after['cover']['height']);
    }

    #[Test]
    public function every_screen_that_shows_a_size_shows_the_measured_one(): void
    {
        // Three read boundaries display artwork dimensions, and one of them
        // being right is not the claim. Each was reading `assets.width`, which
        // nothing writes; each must now report null until a measurement exists.
        $cover = $this->storedArtwork($this->png(880, 880));
        $viewer = User::factory()->create(['role' => UserRole::Owner, 'is_active' => true]);

        $detail = $this->app->make(AssetDetailQuery::class);
        $picker = $this->app->make(ReleasePickerQuery::class);

        $this->assertNull($detail->forAsset($cover, $viewer)['width']);
        $this->assertNull($this->pickerRow($picker, $cover)['width']);

        $this->app->make(MeasureArtwork::class)->handle($cover);

        $this->assertSame(880, $detail->forAsset($cover->refresh(), $viewer)['width']);
        $this->assertSame(880, $this->pickerRow($picker, $cover)['width']);
    }

    #[Test]
    public function the_measurer_never_reads_anything_but_a_local_file(): void
    {
        // No measurer may be talked into fetching an arbitrary address.
        $measurer = new NativeImageMeasurer;

        $this->assertNull($measurer->measure('https://example.test/cover.png'));
        $this->assertNull($measurer->measure('/no/such/file.png'));
    }

    // ----------------------------------------------------------- fixtures

    /**
     * @return list<string>
     */
    private function codes(Asset $asset): array
    {
        return array_map(
            static fn ($issue): string => $issue->code,
            $this->app->make(ValidateArtwork::class)->handle($asset),
        );
    }

    private function measured(ArtworkMeasurement $measurement): Asset
    {
        $asset = $this->storedArtwork('placeholder');

        $this->app->instance(ImageMeasurer::class, new FakeImageMeasurer($measurement));
        $this->app->make(MeasureArtwork::class)->handle($asset);

        return $asset->refresh();
    }

    private function storedArtwork(string $contents): Asset
    {
        // The disk is named explicitly: the factory pins 'local', and an asset
        // whose disk does not resolve to the in-memory store would be spooled
        // from somewhere these bytes are not.
        $asset = Asset::factory()->artwork()->verified()->create(['disk' => 'memory']);

        $this->store->put($asset->path, $contents);

        return $asset;
    }

    /**
     * @return array<string, mixed>
     */
    private function pickerRow(ReleasePickerQuery $picker, Asset $asset): array
    {
        foreach ($picker->artwork((string) $asset->original_filename) as $row) {
            if (($row['uuid'] ?? null) === $asset->uuid) {
                return $row;
            }
        }

        $this->fail('The picker did not return the cover, so nothing below it was tested.');
    }

    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();

        imagedestroy($image);

        return $bytes;
    }
}
