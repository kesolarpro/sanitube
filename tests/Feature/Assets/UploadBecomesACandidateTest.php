<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Catalog\Models\Track;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Enums\TrackCandidateStatus;
use SaniTube\Ingestion\Events\TrackCandidateCreated;
use SaniTube\Ingestion\Models\TrackCandidate;
use SaniTube\Ingestion\Services\RegisterUploadedMaster;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * UPL-003 — the bridge a file uploaded from a browser had no way across.
 *
 * A `TrackCandidate` — and therefore a `Track` — could only be created two
 * ways: by `ProcessIngestionItem`, which reads from a source an operator points
 * the *server* at, and by `SelectMusicGenerationResult`. UPL-001 gave the
 * platform its first `<input type="file">`, and the bytes it accepted arrived,
 * were verified, and then stopped.
 *
 * So the journey the product exists for ended one step after it began: a person
 * could upload a master from their computer and there was no route from it to a
 * track at all — not through a screen, not through the API, not through a
 * console command.
 *
 * **A candidate, never a Track.** The catalogue is what somebody decided, and a
 * row that appears in it because a file was uploaded is a row nobody decided.
 * Review and promotion are unchanged; this only gets the file into the queue
 * they already operate on.
 */
final class UploadBecomesACandidateTest extends TestCase
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
    public function an_uploaded_master_lands_in_the_review_queue(): void
    {
        // WHO CALLS THIS IN PRODUCTION: RelayedUploadController, immediately
        // after verification, and DirectUploadController::complete on an
        // installation with object storage. The upload screen shows the link.
        $body = $this->upload($this->wav('Ma chanson.wav'))->assertOk()->json();

        $this->assertIsString($body['candidate'], 'The upload must not be a dead end.');

        $candidate = TrackCandidate::query()->where('uuid', $body['candidate'])->firstOrFail();

        $this->assertSame(IngestionSource::ManualUpload, $candidate->source);
        $this->assertSame('Ma chanson.wav', $candidate->original_filename);
    }

    #[Test]
    public function it_is_created_pending_and_left_for_analysis_to_settle(): void
    {
        // Asserted with the event faked, because that is the only way to see
        // the state this service *writes*. Left to run, MED-001 subscribes to
        // TrackCandidateCreated and settles the candidate — to READY, or to
        // WAITING_CAPABILITY on a host with no FFmpeg — before the request
        // finishes. Asserting the settled value here would be asserting whether
        // this machine has FFmpeg installed.
        //
        // PENDING is the honest starting state: the bytes are verified, but
        // nothing has yet looked *inside* them, and a candidate published as
        // review-ready before its duration is known is one reviewed without the
        // numbers the review exists to consider.
        Event::fake([TrackCandidateCreated::class]);

        $this->upload($this->wav('a.wav'))->assertOk();

        $this->assertSame(TrackCandidateStatus::Pending, TrackCandidate::query()->firstOrFail()->status);
    }

    #[Test]
    public function analysis_settles_it_without_anybody_asking(): void
    {
        // The other half of the pair above, and the one that proves the wiring
        // rather than the write: an uploaded master goes through the same
        // analysis an imported one does, so it does not sit at PENDING for ever
        // waiting for a step nothing triggers.
        $this->upload($this->wav('a.wav'))->assertOk();

        $this->assertNotSame(
            TrackCandidateStatus::Pending,
            TrackCandidate::query()->firstOrFail()->status,
            'Something must have looked inside the file.',
        );
    }

    #[Test]
    public function the_filename_is_a_suggestion_and_never_the_catalogue(): void
    {
        // A filename is not a title. It is the best thing available when nobody
        // has said what this recording is called, which is why it lands in a
        // `suggested_` field and why no Track exists yet.
        $this->upload($this->wav('01 - Ma chanson.wav'))->assertOk();

        $candidate = TrackCandidate::query()->firstOrFail();

        $this->assertNotNull($candidate->suggested_title);
        $this->assertSame(0, Track::query()->count(), 'Uploading is not deciding.');
    }

    #[Test]
    public function artwork_becomes_no_candidate(): void
    {
        // A cover is not a recording, and a review queue full of images is a
        // review queue nobody reads.
        $body = $this->upload($this->jpeg('cover.jpg'), AssetKind::Artwork)->assertOk()->json();

        $this->assertNull($body['candidate']);
        $this->assertSame(0, TrackCandidate::query()->count());
    }

    #[Test]
    public function uploading_the_same_master_twice_produces_one_candidate_per_upload_and_names_the_duplicate(): void
    {
        // The same master legitimately arrives twice, so the second upload is a
        // real upload and gets its own candidate — settled as DUPLICATE, with
        // the asset it duplicates named, so the reviewer decides rather than
        // discovering later.
        $this->upload($this->wav('a.wav'))->assertOk();
        $second = $this->upload($this->wav('a.wav'))->assertOk()->json();

        $candidate = TrackCandidate::query()->where('uuid', $second['candidate'])->firstOrFail();

        $this->assertSame(TrackCandidateStatus::Duplicate, $candidate->status);
        $this->assertNotNull($candidate->matched_asset_id);
    }

    #[Test]
    public function a_duplicate_names_the_track_that_already_holds_the_bytes(): void
    {
        // "You already have this" is much less useful than "you already have
        // this, and it is that track".
        $this->upload($this->wav('a.wav'))->assertOk();

        $original = Asset::query()->firstOrFail();
        $track = Track::factory()->create(['master_asset_id' => $original->id]);

        $second = $this->upload($this->wav('a.wav'))->assertOk()->json();

        $candidate = TrackCandidate::query()->where('uuid', $second['candidate'])->firstOrFail();

        $this->assertSame($track->id, $candidate->matched_track_id);
    }

    #[Test]
    public function registering_the_same_asset_twice_never_makes_a_second_candidate(): void
    {
        // The failure that matters. A person presses the button twice, a
        // completion call is repeated after a timeout — and a second candidate
        // for one asset would put one recording in the review queue twice,
        // where promoting both would put it in the catalogue twice.
        $this->upload($this->wav('a.wav'))->assertOk();

        $asset = Asset::query()->firstOrFail();
        $service = $this->app->make(RegisterUploadedMaster::class);

        $first = TrackCandidate::query()->firstOrFail();

        $this->assertSame($first->id, $service->handle($asset)->id);
        $this->assertSame($first->id, $service->handle($asset)->id);
        $this->assertSame(1, TrackCandidate::query()->count());
    }

    #[Test]
    public function a_master_that_did_not_verify_becomes_no_candidate(): void
    {
        // A quarantined master is one whose bytes did not come back as they
        // went in. `PromoteTrackCandidate` refuses to promote a candidate whose
        // asset is not verified, so a candidate made for one would be a queue
        // entry that exists only to be stuck — visible, actionable-looking, and
        // permanently refusing.
        $this->upload($this->wav('a.wav'))->assertOk();

        TrackCandidate::query()->delete();

        $asset = Asset::query()->firstOrFail();
        $asset->forceFill(['status' => AssetStatus::Quarantined])->save();

        $service = $this->app->make(RegisterUploadedMaster::class);

        $this->assertNull($service->handle($asset->refresh()));
        $this->assertSame(0, TrackCandidate::query()->count());
    }

    #[Test]
    public function the_same_event_is_raised_as_for_an_imported_file(): void
    {
        // So MED-001's analysis and everything else listening treat an uploaded
        // master exactly as they treat an imported one. A separate event would
        // mean a second thing to remember to subscribe to.
        Event::fake([TrackCandidateCreated::class]);

        $this->upload($this->wav('a.wav'))->assertOk();

        Event::assertDispatched(TrackCandidateCreated::class);
    }

    // ----------------------------------------------------------- fixtures

    /**
     * @return TestResponse<JsonResponse>
     */
    private function upload(UploadedFile $file, AssetKind $kind = AssetKind::AudioMaster): TestResponse
    {
        return $this->actingAs($this->user())->post('/assets/uploads/relay', [
            'kind' => $kind->value,
            'file' => $file,
        ]);
    }

    private function wav(string $name): UploadedFile
    {
        // A real RIFF/WAVE header, so finfo reports audio from the bytes rather
        // than from the name — the trap UPL-001's report records.
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
        $path = tempnam(sys_get_temp_dir(), 'sanitube-upl3-');

        self::assertIsString($path);
        file_put_contents($path, $contents);

        $this->temporary[] = $path;

        return new UploadedFile($path, $name, null, null, test: true);
    }

    private function user(): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    }
}
