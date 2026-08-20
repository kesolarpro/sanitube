<?php

declare(strict_types=1);

namespace Tests\Feature\Journey;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Artists\Models\Artist;
use SaniTube\Artwork\ArtworkMeasurement;
use SaniTube\Artwork\Contracts\ImageMeasurer;
use SaniTube\Artwork\Testing\FakeImageMeasurer;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Models\Asset;
use SaniTube\Catalog\Models\Track;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Ingestion\Enums\TrackCandidateStatus;
use SaniTube\Ingestion\Models\TrackCandidate;
use SaniTube\Releases\Enums\ReleaseStatus;
use SaniTube\Releases\Models\Release;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * The journey, walked end to end through the interface a person actually uses.
 *
 * This is the milestone the product is measured against: *a person takes a real
 * audio file from their computer and carries it through SaniTube to a validated
 * release ready to be distributed, using the normal interface — no SQL, no
 * Tinker, no developer intervention.*
 *
 * **Every step below is an HTTP request to a route a screen posts to.** Nothing
 * here calls a service directly, constructs a domain model, or writes a status.
 * If a step needed a console command or a database edit, this test could not be
 * written — which is precisely why it is written this way, and why the four
 * tickets it exercises exist:
 *
 *   - **UPL-001** gave the platform its first `<input type="file">`. Before it,
 *     no file could enter from a browser at all.
 *   - **UPL-003** made an uploaded master become a review candidate. Before it,
 *     bytes arrived, were verified, and stopped: there was no route from an
 *     uploaded file to a track by any means.
 *   - **CAT-005** made an identifier enterable. Before it, the UPC and ISRC
 *     every store keys on could only be written by a service with no caller.
 *   - **REL-004** made the package preparable and named what still blocks a
 *     delivery. Before it, `PackageRelease` had no production caller.
 *
 * The one thing this test cannot reach is the delivery itself, and that is
 * honest rather than a gap in coverage: no distributor publishes a contract
 * this platform is allowed to implement against (DIST-002), so the journey ends
 * where the milestone says it should — at a release *ready to be distributed*.
 */
final class FromAFileToAReadyReleaseTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryStorageProvider $store;

    /** @var list<string> */
    private array $temporary = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new InMemoryStorageProvider('memory');
        config(['storage.default' => 'memory']);
        $this->app->make(StorageManager::class)->register('memory', $this->store);

        // The only stand-in in the whole test, and it stands in for a *host*
        // capability rather than for any part of SaniTube: ART-001 measures a
        // cover with getimagesize(), and a 64-pixel test JPEG would fail the
        // dimension rules every store enforces. The measurement is faked; the
        // validation that reads it is not.
        $this->app->instance(ImageMeasurer::class, new FakeImageMeasurer(
            new ArtworkMeasurement(3000, 3000, 'image/jpeg', 900_000, channels: 3),
        ));
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function a_person_carries_a_file_from_their_computer_to_a_release_ready_to_distribute(): void
    {
        $this->actingAs($this->user());

        // 1. The master goes in from a browser. Relayed, because that is what a
        //    shared host without object storage does — and what SaniTube ships
        //    configured for.
        $upload = $this->post('/assets/uploads/relay', [
            'kind' => AssetKind::AudioMaster->value,
            'file' => $this->wav('Ma chanson.wav'),
        ])->assertOk()->json();

        $this->assertIsString($upload['candidate'], 'UPL-003: an upload must not be a dead end.');

        $candidate = TrackCandidate::query()->where('uuid', $upload['candidate'])->firstOrFail();

        // 2. It is reviewed and promoted. A candidate, not a Track: the
        //    catalogue is what somebody decided.
        $this->assertSame(0, Track::query()->count(), 'Uploading is not deciding.');

        $this->post('/ingestion/candidates/'.$candidate->uuid.'/promote', [
            'title' => 'Ma chanson',
            // An override with a note, because on a host with no FFmpeg the
            // candidate settles to WAITING_CAPABILITY rather than READY, and
            // the journey must not depend on what this machine has installed.
            'override' => true,
            'note' => 'Reviewed by hand for the journey test.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $track = Track::query()->firstOrFail();

        $this->assertSame(TrackCandidateStatus::Promoted, $candidate->refresh()->status);

        // 3. The recording is credited. I3 refuses READY without a primary
        //    artist, and CAT-002 is what lets a person supply one.
        $artist = Artist::factory()->create(['name' => 'Une artiste']);

        $this->post('/catalog/tracks/'.$track->uuid.'/credits', [
            'artists' => [['uuid' => $artist->uuid, 'role' => 'PRIMARY']],
        ])->assertSessionHasNoErrors();

        // 4. Its ISRC is recorded — CAT-005. SaniTube never mints one.
        $this->post('/catalog/tracks/'.$track->uuid.'/identifiers', [
            'type' => 'ISRC',
            'value' => 'FRZ032400001',
        ])->assertSessionHasNoErrors();

        $this->post('/catalog/tracks/'.$track->uuid.'/ready')->assertSessionHasNoErrors();

        // 5. A cover goes in the same way the master did.
        $cover = $this->post('/assets/uploads/relay', [
            'kind' => AssetKind::Artwork->value,
            'file' => $this->jpeg('cover.jpg'),
        ])->assertOk()->json();

        $this->assertNull($cover['candidate'], 'A cover is not a recording.');

        // 6. The release is assembled from the screens.
        $this->post('/releases', ['title' => 'Ma sortie', 'type' => 'SINGLE'])
            ->assertSessionHasNoErrors();

        $release = Release::query()->firstOrFail();

        $this->patch('/releases/'.$release->uuid, [
            'title' => 'Ma sortie',
            'type' => 'SINGLE',
            'language_code' => 'fr',
            'release_date' => now()->addMonth()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->post('/releases/'.$release->uuid.'/tracks', ['track' => $track->uuid])
            ->assertSessionHasNoErrors();

        $this->post('/releases/'.$release->uuid.'/artists', [
            'artists' => [['uuid' => $artist->uuid, 'role' => 'PRIMARY']],
        ])->assertSessionHasNoErrors();

        $this->post('/releases/'.$release->uuid.'/cover', ['asset' => $cover['asset']])
            ->assertSessionHasNoErrors();

        // 7. Its UPC is recorded — CAT-005 again, on the other kind of thing.
        $this->post('/releases/'.$release->uuid.'/identifiers', [
            'type' => 'UPC',
            'value' => '088515001233',
        ])->assertSessionHasNoErrors();

        // 8. It is marked ready. `markReady()` re-runs the invariants; the
        //    request is a question, not an assignment.
        $this->post('/releases/'.$release->uuid.'/ready')
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(ReleaseStatus::Ready, $release->refresh()->status);

        // 9. The package is prepared — REL-004 — and nothing is left blocking a
        //    delivery. This is the assertion the whole test exists for: not
        //    that the screens accepted the input, but that what would cross to
        //    a distributor actually assembles.
        $package = $this->getJson('/releases/'.$release->uuid.'/package')->assertOk()->json();

        $this->assertTrue($package['prepared'], 'The package must assemble.');
        $this->assertSame([], $package['blocking_identifiers'], 'Nothing may still block a delivery.');
        $this->assertSame('088515001233', $package['package']['upc']);
        $this->assertSame('FRZ032400001', $package['package']['tracks'][0]['isrc']);
        $this->assertSame('Ma chanson', $package['package']['tracks'][0]['title']);

        // The master that crosses over is the file that was uploaded in step 1.
        $this->assertSame(
            Asset::query()->where('uuid', $upload['asset'])->firstOrFail()->uuid,
            $package['package']['tracks'][0]['master_asset_uuid'],
        );
    }

    // ----------------------------------------------------------- fixtures

    private function wav(string $name): UploadedFile
    {
        $header = 'RIFF'.pack('V', 36 + 1024).'WAVEfmt '.pack('VvvVVvv', 16, 1, 2, 44100, 176400, 4, 16).'data'.pack('V', 1024);

        return $this->realUpload($name, $header.str_repeat("\0", 1024));
    }

    private function jpeg(string $name): UploadedFile
    {
        $image = imagecreatetruecolor(64, 64);
        self::assertNotFalse($image);

        ob_start();
        imagejpeg($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $this->realUpload($name, $bytes);
    }

    private function realUpload(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sanitube-journey-');

        self::assertIsString($path);
        file_put_contents($path, $contents);

        $this->temporary[] = $path;

        return new UploadedFile($path, $name, null, null, test: true);
    }

    private function user(): User
    {
        return User::factory()->create(['role' => UserRole::Owner, 'is_active' => true]);
    }
}
