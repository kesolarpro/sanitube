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
use SaniTube\Catalog\Models\Track;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Ingestion\Models\IngestionBatch;
use SaniTube\Ingestion\Models\TrackCandidate;
use SaniTube\Releases\Enums\ReleaseStatus;
use SaniTube\Releases\Models\Release;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * Several files at once, through to a release — the second journey.
 *
 * `FromAFileToAReadyReleaseTest` proves one file can cross the platform.
 * That is a different claim from the one the product is now measured against,
 * which is about a *catalogue*: many files chosen together, imported as a
 * batch, reviewed, and assembled into a release.
 *
 * **Every step is an HTTP request to a route a screen posts to.** Nothing here
 * calls a service directly, constructs a domain model, or writes a status. The
 * one exception is running the queue, which is what a worker does on a real
 * installation and is the only honest way to advance a batch inside a test.
 *
 * Three files rather than nine hundred, deliberately. What this holds in place
 * is the *shape* — one batch, one item per file, one job per item, a request
 * that carries references and no bytes — and that shape is what makes nine
 * hundred possible. Claiming nine hundred were tested because a loop ran nine
 * hundred times would be claiming something about a machine, not about the
 * platform.
 */
final class FromManyFilesToAReleaseTest extends TestCase
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

        // Stands in for a *host* capability, not for any part of SaniTube:
        // ART-001 measures a cover with getimagesize(), and a 64-pixel test
        // JPEG would fail the dimension rules every store enforces.
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
    public function a_person_imports_several_recordings_at_once_and_builds_a_release_from_them(): void
    {
        $this->actingAs($this->user());

        $titles = ['Premiere piste', 'Deuxieme piste', 'Troisieme piste'];

        // 1. Deposit them. Relayed, because that is what a shared account
        //    without object storage does — and what SaniTube ships configured
        //    for.
        $references = [];

        foreach ($titles as $title) {
            $references[] = $this->post('/ingestion/import/relay', [
                'file' => $this->wav($title.'.wav'),
            ])->assertOk()->json('reference');
        }

        // The inbox is the durable state, read from storage. A reload here
        // loses nothing that has landed.
        $waiting = $this->get('/ingestion/import')->assertOk()->viewData('page')['props']['capability']['waiting'];

        $this->assertTrue($waiting['available']);
        $this->assertCount(3, $waiting['files']);

        // 2. Import them as one batch. The request carries references and no
        //    bytes; every byte moves in a queued job.
        $this->post('/ingestion/batches', ['source' => 'MANUAL_UPLOAD', 'references' => $references])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $batch = IngestionBatch::query()->firstOrFail();

        // A real worker turn. Not a shortcut around the pipeline — the same
        // jobs a queue worker would run, run here.
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--tries' => 1])->assertSuccessful();

        // 3. What the batch made, read from the screen a person would look at.
        $progress = $this->get('/ingestion/batches/'.$batch->uuid)
            ->assertOk()
            ->viewData('page')['props']['batch'];

        $this->assertSame(3, $progress['items']['total']);
        $this->assertSame(3, $progress['items']['IMPORTED'], 'Every file should have been imported.');

        $this->assertSame(3, TrackCandidate::query()->count());
        $this->assertSame(0, Track::query()->count(), 'Importing is not deciding.');

        // 4. Promote each candidate. A batch does not put anything in the
        //    catalogue; a person does, one decision at a time.
        foreach (TrackCandidate::query()->orderBy('id')->get() as $index => $candidate) {
            $this->post('/ingestion/candidates/'.$candidate->uuid.'/promote', [
                'title' => $titles[$index],
                // Override with a note, because on a host with no FFmpeg the
                // candidate settles to WAITING_CAPABILITY rather than READY and
                // the journey must not depend on what this machine has.
                'override' => true,
                'note' => 'Reviewed by hand for the journey test.',
            ])->assertSessionHasNoErrors();
        }

        $tracks = Track::query()->orderBy('id')->get();

        $this->assertCount(3, $tracks);

        // 5. Credit and identify each one, then mark it releasable.
        $artist = Artist::factory()->create(['name' => 'Une artiste']);
        $designation = 0;

        foreach ($tracks as $track) {
            $this->post('/catalog/tracks/'.$track->uuid.'/credits', [
                'artists' => [['uuid' => $artist->uuid, 'role' => 'PRIMARY']],
            ])->assertSessionHasNoErrors();

            // Numbered from a counter, never from `$track->id`: an ISRC is
            // exactly twelve characters, and interpolating a primary key
            // produces a thirteenth as soon as ids reach double figures —
            // which never happens on SQLite and always does on MySQL.
            $this->post('/catalog/tracks/'.$track->uuid.'/identifiers', [
                'type' => 'ISRC',
                'value' => 'FRZ0324'.sprintf('%05d', ++$designation),
            ])->assertSessionHasNoErrors();

            $this->post('/catalog/tracks/'.$track->uuid.'/ready')->assertSessionHasNoErrors();
        }

        // 6. A cover, in through the same door.
        $cover = $this->post('/assets/uploads/relay', [
            'kind' => AssetKind::Artwork->value,
            'file' => $this->jpeg('cover.jpg'),
        ])->assertOk()->json();

        // 7. Assemble the release from the screens.
        $this->post('/releases', ['title' => 'Mon EP', 'type' => 'EP'])->assertSessionHasNoErrors();

        $release = Release::query()->firstOrFail();

        $this->patch('/releases/'.$release->uuid, [
            'title' => 'Mon EP',
            'type' => 'EP',
            'language_code' => 'fr',
            'release_date' => now()->addMonth()->toDateString(),
        ])->assertSessionHasNoErrors();

        foreach ($tracks as $track) {
            $this->post('/releases/'.$release->uuid.'/tracks', ['track' => $track->uuid])
                ->assertSessionHasNoErrors();
        }

        $this->post('/releases/'.$release->uuid.'/artists', [
            'artists' => [['uuid' => $artist->uuid, 'role' => 'PRIMARY']],
        ])->assertSessionHasNoErrors();

        $this->post('/releases/'.$release->uuid.'/cover', ['asset' => $cover['asset']])
            ->assertSessionHasNoErrors();

        $this->post('/releases/'.$release->uuid.'/identifiers', [
            'type' => 'UPC',
            'value' => '088515001233',
        ])->assertSessionHasNoErrors();

        $this->post('/releases/'.$release->uuid.'/ready')->assertSessionHasNoErrors();

        $this->assertSame(ReleaseStatus::Ready, $release->refresh()->status);

        // 8. The package assembles, with all three recordings on it and nothing
        //    left blocking a delivery.
        $package = $this->getJson('/releases/'.$release->uuid.'/package')->assertOk()->json();

        $this->assertTrue($package['prepared']);
        $this->assertSame([], $package['blocking_identifiers']);
        $this->assertCount(3, $package['package']['tracks']);
        $this->assertSame([1, 2, 3], array_column($package['package']['tracks'], 'track_number'));
        $this->assertSame($titles, array_column($package['package']['tracks'], 'title'));
    }

    // ----------------------------------------------------------- fixtures

    private function wav(string $name): UploadedFile
    {
        // Distinct bytes per file, so three recordings are three recordings
        // rather than three copies the de-duplicator would rightly collapse.
        $header = 'RIFF'.pack('V', 36 + 1024).'WAVEfmt '.pack('VvvVVvv', 16, 1, 2, 44100, 176400, 4, 16).'data'.pack('V', 1024);

        return $this->realUpload($name, $header.str_pad($name, 1024, "\0"));
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
        $path = tempnam(sys_get_temp_dir(), 'sanitube-many-');

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
