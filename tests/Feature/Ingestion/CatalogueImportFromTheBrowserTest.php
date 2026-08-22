<?php

declare(strict_types=1);

namespace Tests\Feature\Ingestion;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Services\UploadAdmission;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Jobs\ProcessIngestionItemJob;
use SaniTube\Ingestion\Models\IngestionBatch;
use SaniTube\Ingestion\Models\IngestionItem;
use SaniTube\Operations\Services\BackgroundWork;
use SaniTube\Storage\Providers\LocalStorageProvider;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * BULK-001 — a catalogue can be brought in from a browser.
 *
 * `ManualUploadReader` has always described bytes arriving "through the
 * ordinary storage pipeline into a reserved inbox prefix, and the batch refers
 * to them by key". Everything downstream of that sentence was built — the
 * reader, the batch, the per-item job, the retries, the candidate, the batch
 * screens — and **nothing could put a byte in the inbox from a browser**. The
 * one route that starts a batch from references had no caller either.
 *
 * So a person with nine hundred masters on their laptop had exactly two
 * options: upload them one at a time through a screen that made one asset per
 * file and no batch at all, or be given SSH.
 *
 * **The shape is what matters here, not the count.** The request that starts an
 * import carries references and no bytes; every byte moves in a queued job that
 * outlives the tab. That is the only arrangement in which nine hundred files
 * can be imported at all, and it is the arrangement these tests hold in place.
 */
final class CatalogueImportFromTheBrowserTest extends TestCase
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

        Queue::fake();
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

    // --------------------------------------------------------------- access

    #[Test]
    public function every_import_route_is_behind_the_catalogue_role(): void
    {
        // Depositing several hundred files is a write. The hidden screen is
        // presentation; these are the checks.
        $member = $this->user(UserRole::Member);

        $this->actingAs($member)->get('/ingestion/import')->assertForbidden();
        $this->actingAs($member)->postJson('/ingestion/import/capabilities', [
            'files' => [['name' => 'a.wav', 'type' => 'audio/wav']],
        ])->assertForbidden();
        $this->actingAs($member)->post('/ingestion/import/relay', ['file' => $this->wav('a.wav')])->assertForbidden();
        $this->actingAs($member)->delete('/ingestion/import/inbox')->assertForbidden();
    }

    #[Test]
    public function the_import_screen_is_behind_authentication(): void
    {
        $this->get('/ingestion/import')->assertRedirect(route('login'));
    }

    // ------------------------------------------------------- the direct path

    #[Test]
    public function a_capable_store_mints_one_capability_per_file_and_chooses_every_key(): void
    {
        // WHO CALLS THIS IN PRODUCTION: the import screen, once per file, on an
        // installation whose storage can take a write that never enters PHP.
        $body = $this->actingAs($this->user())->postJson('/ingestion/import/capabilities', [
            'files' => [
                ['name' => 'Ma chanson.wav', 'type' => 'audio/wav'],
                ['name' => 'Autre.flac', 'type' => 'audio/flac'],
            ],
        ])->assertOk()->json();

        $this->assertCount(2, $body['files']);

        foreach ($body['files'] as $file) {
            // The server chose it. A client that named its own key would be
            // naming a path, and a path a client controls eventually contains
            // `..`.
            $this->assertStringStartsWith('inbox/', $file['reference']);
            $this->assertNotEmpty($file['upload']['url']);
        }

        // The name survives, because `ManualUploadReader::originalFilename()`
        // is the basename of the key and `SuggestTitle` reads it.
        $this->assertStringEndsWith('Ma chanson.wav', $body['files'][0]['reference']);
    }

    #[Test]
    public function a_filename_can_never_introduce_a_directory(): void
    {
        // The whole reason the key is the server's. Each of these is a real
        // attempt to write outside the inbox.
        // An empty name is absent from this list because the form request
        // refuses it first, with `required` — the boundary it belongs at.
        //
        // What stops the rest is `OriginalFilename::sanitise()`, which
        // normalises Windows separators and takes `basename()`. `InboxDeposit`
        // strips separators again afterwards, and BULK-001's mutation pass
        // showed that second strip is unreachable — deliberately kept as
        // defence in depth, and recorded as unreachable rather than described
        // as a check. What this test pins is the *property*: whatever a client
        // sends, the composed key is three segments under the inbox.
        $names = ['../../etc/passwd', 'a/../../b.wav', 'nested/deep/x.wav', '..', '.', '.hidden', 'x\\y.wav'];

        $body = $this->actingAs($this->user())->postJson('/ingestion/import/capabilities', [
            'files' => array_map(static fn (string $name): array => ['name' => $name, 'type' => 'audio/wav'], $names),
        ])->assertOk()->json();

        foreach ($body['files'] as $file) {
            if (! isset($file['reference'])) {
                continue;
            }

            $this->assertStringStartsWith('inbox/', $file['reference']);
            $this->assertStringNotContainsString('..', $file['reference']);

            // `inbox/<uuid>/<name>` — three segments, and the name is one of
            // them. Any more and the filename introduced a directory.
            $this->assertCount(3, explode('/', $file['reference']), $file['reference']);
        }
    }

    #[Test]
    public function a_type_this_installation_refuses_never_costs_an_upload(): void
    {
        // A presigned PUT cannot be taken back. A capability handed out for a
        // file the platform will refuse costs somebody an upload before it
        // costs them a refusal.
        $body = $this->actingAs($this->user())->postJson('/ingestion/import/capabilities', [
            'files' => [['name' => 'notes.txt', 'type' => 'text/plain']],
        ])->assertOk()->json();

        $this->assertSame('MEDIA_TYPE_NOT_ACCEPTED', $body['files'][0]['code']);
        $this->assertArrayNotHasKey('upload', $body['files'][0]);
    }

    #[Test]
    public function a_store_that_cannot_take_a_direct_write_says_so_rather_than_pretending(): void
    {
        // The local disk cannot mint an upload URL and must not pretend to. The
        // screen reads the same fact from its own payload and relays instead.
        $this->useLocalDisk();

        $this->actingAs($this->user())->postJson('/ingestion/import/capabilities', [
            'files' => [['name' => 'a.wav', 'type' => 'audio/wav']],
        ])->assertStatus(422)->assertJson(['code' => 'DIRECT_DEPOSIT_UNSUPPORTED']);
    }

    #[Test]
    public function one_request_cannot_declare_an_unbounded_number_of_files(): void
    {
        config(['ingestion.deposit_per_request' => 2]);

        $this->actingAs($this->user())->postJson('/ingestion/import/capabilities', [
            'files' => array_fill(0, 3, ['name' => 'a.wav', 'type' => 'audio/wav']),
        ])->assertStatus(422)->assertJson(['code' => 'TOO_MANY_FILES']);
    }

    // ------------------------------------------------------ the relayed path

    #[Test]
    public function a_shared_account_relays_the_file_into_the_inbox(): void
    {
        // WHO CALLS THIS IN PRODUCTION: the import screen on an installation
        // with no object storage — which is what cPanel is, and what SaniTube
        // ships configured for.
        $body = $this->actingAs($this->user())
            ->post('/ingestion/import/relay', ['file' => $this->wav('Ma chanson.wav')])
            ->assertOk()
            ->json();

        $this->assertStringStartsWith('inbox/', $body['reference']);
        $this->assertTrue($this->store->exists($body['reference']), 'The bytes must actually be there.');
    }

    #[Test]
    public function the_relayed_path_reads_the_type_from_the_bytes_and_not_from_the_name(): void
    {
        // The trap UPL-001's report records: `UploadedFile::fake()` derives the
        // media type from the *filename*, so a test written with one passes
        // against an implementation that trusts the extension — which is the
        // bug it exists to catch. These are real bytes.
        $this->actingAs($this->user())
            ->post('/ingestion/import/relay', ['file' => $this->realUpload('master.wav', 'this is not audio')])
            ->assertStatus(422)
            ->assertJson(['code' => 'MEDIA_TYPE_NOT_ACCEPTED']);

        $this->assertSame([], $this->store->files('inbox/'));
    }

    #[Test]
    public function a_file_larger_than_this_installation_accepts_is_refused(): void
    {
        // Per kind, which is the shape of this setting. UPL-004 found this
        // line replacing the whole array with a scalar, so the configured
        // maximum resolved to nought — and the import path read nought as
        // "refuse everything above zero bytes" while the single-file upload
        // path read the same nought as "no application ceiling". The test
        // passed on the disagreement. Both paths now mean the second thing.
        config(['assets.max_upload_bytes.'.AssetKind::AudioMaster->value => 128]);

        $this->actingAs($this->user())
            ->post('/ingestion/import/relay', ['file' => $this->wav('big.wav', 8)])
            ->assertStatus(422)
            ->assertJson(['code' => 'FILE_TOO_LARGE']);

        $this->assertSame([], $this->store->files('inbox/'));
    }

    // ------------------------------------------------------- what is waiting

    #[Test]
    public function the_screen_reads_what_is_waiting_from_storage_rather_than_from_the_browser(): void
    {
        // This is what makes the import survive a reload. A screen that kept
        // its queue in JavaScript would lose several hundred deposits to one
        // accidental refresh with no way to find out what had landed.
        $this->actingAs($this->user())->post('/ingestion/import/relay', ['file' => $this->wav('a.wav')])->assertOk();
        $this->actingAs($this->user())->post('/ingestion/import/relay', ['file' => $this->wav('b.wav')])->assertOk();

        $payload = $this->screen();

        $this->assertTrue($payload['waiting']['available']);
        $this->assertCount(2, $payload['waiting']['files']);
        $this->assertSame(['a.wav', 'b.wav'], array_column($payload['waiting']['files'], 'name'));
    }

    #[Test]
    public function an_unreadable_inbox_is_not_reported_as_an_empty_one(): void
    {
        // "Nothing is waiting" and "we could not ask" lead a person to opposite
        // actions, and only one of them is fixed by uploading the files again.
        $this->store->failWith('The store is not answering.');

        $payload = $this->screen();

        $this->assertFalse($payload['waiting']['available']);
        $this->assertSame([], $payload['waiting']['files']);
    }

    // ---------------------------------------------------------- the batch

    #[Test]
    public function importing_what_is_waiting_queues_one_job_per_file_and_moves_no_bytes(): void
    {
        // The shape the whole ticket exists for. The request carries
        // references; every byte moves in a job that outlives the tab. Nine
        // hundred files cannot be imported any other way.
        $references = [];

        foreach (['a.wav', 'b.wav', 'c.wav'] as $name) {
            $references[] = $this->actingAs($this->user())
                ->post('/ingestion/import/relay', ['file' => $this->wav($name)])
                ->assertOk()
                ->json('reference');
        }

        $this->actingAs($this->user())
            ->post('/ingestion/batches', ['source' => 'MANUAL_UPLOAD', 'references' => $references])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $batch = IngestionBatch::query()->firstOrFail();

        $this->assertSame(IngestionSource::ManualUpload, $batch->source);
        $this->assertSame(3, IngestionItem::query()->where('batch_id', $batch->id)->count());

        Queue::assertPushed(ProcessIngestionItemJob::class, 3);
    }

    #[Test]
    public function the_person_who_started_the_import_is_recorded(): void
    {
        // An import is where several hundred things enter at once, and "who
        // started this" is the first question asked when one turns out wrong.
        $operator = $this->user();

        $reference = $this->actingAs($operator)
            ->post('/ingestion/import/relay', ['file' => $this->wav('a.wav')])
            ->json('reference');

        $this->actingAs($operator)
            ->post('/ingestion/batches', ['source' => 'MANUAL_UPLOAD', 'references' => [$reference]])
            ->assertSessionHasNoErrors();

        $this->assertSame($operator->getKey(), IngestionBatch::query()->firstOrFail()->created_by);
    }

    #[Test]
    public function a_paused_installation_refuses_the_import_by_code_rather_than_by_crashing(): void
    {
        // A defect BULK-001 found rather than introduced. `StartIngestionBatch`
        // asks OPS-002 for capacity and throws `WorkRefused`; the controller
        // caught only `IngestionException`, so on an installation with
        // background work paused — a supported, deliberate state — starting an
        // import threw straight past the handler and answered 500.
        //
        // The exception already carried a code written to be turned into an
        // instruction. Nothing was catching it to do so.
        $reference = $this->actingAs($this->user())
            ->post('/ingestion/import/relay', ['file' => $this->wav('a.wav')])
            ->json('reference');

        $this->app->make(BackgroundWork::class)->pause(null, 'Maintenance.');

        $this->actingAs($this->user())
            ->post('/ingestion/batches', ['source' => 'MANUAL_UPLOAD', 'references' => [$reference]])
            ->assertRedirect()
            ->assertSessionHasErrors(['import' => 'BACKGROUND_WORK_PAUSED']);

        $this->assertSame(0, IngestionBatch::query()->count());
    }

    #[Test]
    public function nothing_outside_the_inbox_can_be_imported(): void
    {
        // Otherwise "import this object" would be a way to name any object in
        // the store, including a master.
        $this->store->put('masters/secret/original', 'not yours');

        $this->actingAs($this->user())
            ->post('/ingestion/batches', ['source' => 'MANUAL_UPLOAD', 'references' => ['masters/secret/original']])
            ->assertSessionHasErrors(['import' => 'OUTSIDE_INBOX']);

        $this->assertSame(0, IngestionBatch::query()->count());
    }

    // ---------------------------------------------------------- discarding

    #[Test]
    public function discarding_empties_the_inbox_and_touches_nothing_else(): void
    {
        // An import that half-happened leaves objects nobody wants, and on a
        // shared account there is no bucket console to clear them from.
        $this->actingAs($this->user())->post('/ingestion/import/relay', ['file' => $this->wav('a.wav')])->assertOk();
        $this->store->put('masters/keep/original', 'a master');

        $this->actingAs($this->user())->delete('/ingestion/import/inbox')->assertRedirect();

        $this->assertSame([], $this->store->files('inbox/'));
        $this->assertTrue($this->store->exists('masters/keep/original'), 'A master is not the inbox.');
    }

    // ----------------------------------------------------------- fixtures

    // ------------------------------------------------ UPL-004: the real MP3

    #[Test]
    public function a_real_mp3_of_the_size_production_refused_deposits(): void
    {
        // The exact file shape that failed on sanitube.livegine.com: a genuine
        // MP3 — ID3v2 tag then MPEG-1 Layer III frames, so finfo reads
        // audio/mpeg from the bytes — at 4.7 MB, with an accented filename.
        $this->useLocalDisk();

        // This host's own limit decides which of two honest answers is right,
        // and both are asserted. CI runs with upload_max_filesize=2M — the
        // same shape as the shared host that refused the real file — so the
        // ceiling is raised here to prove the deposit itself, and the refusal
        // below is proved on the host's real limit.
        config(['assets.max_upload_bytes.'.AssetKind::AudioMaster->value => 64 * 1024 * 1024]);

        $mp3 = $this->mp3('Armure de Lumière.mp3');

        $this->assertSame('audio/mpeg', $mp3->getMimeType(), 'finfo must read audio from the bytes.');
        $this->assertGreaterThan(4_000_000, (int) $mp3->getSize());

        $php = $this->app->make(UploadAdmission::class)->phpPostLimitBytes();

        if ($php > 0 && $php < (int) $mp3->getSize()) {
            // The production condition: the host itself is smaller than the
            // file. The refusal must name the size, not a generic failure.
            $this->actingAs($this->user())
                ->post('/ingestion/import/relay', ['file' => $mp3])
                ->assertStatus(422)
                ->assertJson(['code' => 'FILE_TOO_LARGE']);

            return;
        }

        $response = $this->actingAs($this->user())
            ->post('/ingestion/import/relay', ['file' => $mp3]);

        $response->assertOk();

        $reference = $response->json('reference');

        $this->assertIsString($reference);
        // The server chose the key: an inbox prefix, a uuid segment, then the
        // sanitised name. The filename is a label, never the identity.
        $this->assertStringStartsWith('inbox/', $reference);
        $this->assertStringEndsWith('Armure de Lumière.mp3', $reference);
    }

    #[Test]
    public function a_real_mp3_within_the_hosts_ceiling_deposits_and_keeps_its_name(): void
    {
        // The same genuine MP3 bytes, sized to fit any host: this proves the
        // deposit path end to end regardless of the runner's php.ini, so the
        // test above can be about the limit and this one about the file.
        // The default in-memory store from setUp, so the bytes can be looked
        // for where they were put.
        $response = $this->actingAs($this->user())
            ->post('/ingestion/import/relay', ['file' => $this->mp3('Armure de Lumière.mp3', frames: 200)]);

        $response->assertOk();

        $reference = $response->json('reference');

        $this->assertIsString($reference);
        $this->assertStringStartsWith('inbox/', $reference);
        $this->assertStringEndsWith('Armure de Lumière.mp3', $reference);
        $this->assertContains($reference, $this->store->files('inbox/'));
    }

    #[Test]
    public function two_files_with_the_same_name_are_two_objects_and_neither_is_lost(): void
    {
        // A catalogue of nine hundred masters arrives with `01 - Intro.mp3` in
        // forty different album folders. The browser sends a name and nothing
        // else, so a key derived from the name alone would have the fortieth
        // deposit silently overwrite the first thirty-nine — and the import
        // would report forty successes for one surviving file.
        //
        // `keyFor` puts a uuid segment between the prefix and the name, which
        // is what stops that. It has been true since BULK-001; nothing held it.
        $user = $this->user();

        $first = $this->actingAs($user)
            ->post('/ingestion/import/relay', ['file' => $this->mp3('01 - Intro.mp3', frames: 40)])
            ->assertOk()
            ->json('reference');

        $second = $this->actingAs($user)
            ->post('/ingestion/import/relay', ['file' => $this->mp3('01 - Intro.mp3', frames: 80)])
            ->assertOk()
            ->json('reference');

        $this->assertIsString($first);
        $this->assertIsString($second);
        $this->assertNotSame($first, $second);

        // Both keys still carry the name — it is a label a person reads in the
        // inbox — and both objects are there to be imported.
        $this->assertStringEndsWith('01 - Intro.mp3', $first);
        $this->assertStringEndsWith('01 - Intro.mp3', $second);

        $inbox = $this->store->files('inbox/');

        $this->assertContains($first, $inbox);
        $this->assertContains($second, $inbox);

        // Not "two keys exist" but "two files' worth of bytes survive": the
        // second deposit is twice the first, and neither is the other.
        $this->assertNotSame($this->store->get($first), $this->store->get($second));
    }

    #[Test]
    public function the_screen_states_the_ceiling_that_will_actually_refuse(): void
    {
        // The defect: the screen promised `maximum_bytes` — two gigabytes by
        // default — while a relayed deposit dies at whatever post_max_size is.
        $this->useLocalDisk();

        $payload = $this->screen();

        $this->assertArrayHasKey('effective_maximum_bytes', $payload);
        $this->assertArrayHasKey('limited_by_php', $payload);

        $php = (int) $payload['php_post_limit_bytes'];
        $configured = (int) $payload['maximum_bytes'];

        if ($php > 0 && $php < $configured) {
            $this->assertSame($php, (int) $payload['effective_maximum_bytes'], 'The binding ceiling is PHP\'s.');
            $this->assertTrue($payload['limited_by_php']);
        } else {
            $this->assertSame($configured, (int) $payload['effective_maximum_bytes']);
        }
    }

    #[Test]
    public function a_body_php_threw_away_is_named_as_the_hosts_limit(): void
    {
        // post_max_size exceeded: PHP empties $_POST and $_FILES, so the
        // request looks exactly like one that carried nothing — and stock
        // validation answers "the file field is required", which the screen
        // could only render as the generic "could not be deposited". That is
        // the message a real 4.7 MB MP3 got.
        $this->useLocalDisk();

        $limit = $this->app->make(UploadAdmission::class)->phpPostLimitBytes();

        if ($limit <= 0) {
            $this->markTestSkipped('This PHP reports no upload limit to exceed.');
        }

        $response = $this->actingAs($this->user())->call(
            'POST',
            '/ingestion/import/relay',
            [],
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json', 'CONTENT_LENGTH' => (string) ($limit + 1)],
        );

        $response->assertStatus(413);
        $this->assertSame('HOST_UPLOAD_LIMIT', $response->json('code'));
        $this->assertSame($limit, $response->json('limit_bytes'));
    }

    #[Test]
    public function a_request_that_genuinely_sent_nothing_still_says_so(): void
    {
        // The guard must never turn an ordinary empty request into a story
        // about host limits — that would send an operator hunting a setting
        // that is not the problem.
        $this->useLocalDisk();

        $response = $this->actingAs($this->user())
            ->postJson('/ingestion/import/relay', []);

        $response->assertStatus(422);
        $this->assertNotSame('HOST_UPLOAD_LIMIT', $response->json('code'));
    }

    #[Test]
    public function a_small_empty_request_is_never_blamed_on_the_host(): void
    {
        // A body of a few bytes cannot have exceeded a megabyte limit. Below
        // the plausibility floor the guard must stay silent, or every stray
        // empty POST would send an operator hunting a limit that is fine.
        $limit = $this->app->make(UploadAdmission::class)->phpPostLimitBytes();

        if ($limit <= 0) {
            $this->markTestSkipped('This PHP reports no upload limit.');
        }

        $response = $this->actingAs($this->user())->call(
            'POST',
            '/ingestion/import/relay',
            [],
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json', 'CONTENT_LENGTH' => '12'],
        );

        $response->assertStatus(422);
        $this->assertNotSame('HOST_UPLOAD_LIMIT', $response->json('code'));
    }

    #[Test]
    public function a_large_body_that_fits_the_limit_is_not_blamed_on_the_host(): void
    {
        // Big enough to be plausible, still inside what PHP accepts: the
        // request failed for some other reason, and saying "too large" would
        // be a confident wrong answer.
        $limit = $this->app->make(UploadAdmission::class)->phpPostLimitBytes();

        if ($limit <= 2048) {
            $this->markTestSkipped('This PHP limit leaves no room below it.');
        }

        $response = $this->actingAs($this->user())->call(
            'POST',
            '/ingestion/import/relay',
            [],
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json', 'CONTENT_LENGTH' => (string) ($limit - 1)],
        );

        $response->assertStatus(422);
        $this->assertNotSame('HOST_UPLOAD_LIMIT', $response->json('code'));
    }

    #[Test]
    public function the_single_file_upload_screen_shares_the_same_rule(): void
    {
        // One rule in UploadAdmission, consulted by both screens. Before
        // UPL-004 the single-file screen had it inline and the import screen
        // had nothing.
        $this->useLocalDisk();

        $admission = $this->app->make(UploadAdmission::class);

        $this->assertSame(
            $admission->effectiveMaximumBytes(AssetKind::AudioMaster, direct: false),
            $this->screen()['effective_maximum_bytes'],
        );
    }

    /**
     * A genuine MP3 the size of the one production refused.
     */
    private function mp3(string $name, int $frames = 4500): UploadedFile
    {
        // ID3v2.3 header, then MPEG-1 Layer III frames at 44.1 kHz. finfo
        // reads audio/mpeg from this; a name alone would prove nothing.
        $id3 = 'ID3'.chr(3).chr(0).chr(0).str_repeat(chr(0), 4);
        $frame = chr(0xFF).chr(0xFB).chr(0xB0).chr(0x00).str_repeat(chr(0), 1040);

        return $this->realUpload($name, $id3.str_repeat($frame, $frames));
    }

    /**
     * @return array<string, mixed>
     */
    private function screen(): array
    {
        $page = $this->actingAs($this->user())->get('/ingestion/import')->assertOk();

        /** @var array<string, mixed> $payload */
        $payload = $page->viewData('page')['props']['capability'];

        return $payload;
    }

    private function useLocalDisk(): void
    {
        // The manager caches its default provider at construction, so the
        // instance has to go before the configuration change can reach it.
        config(['storage.default' => 'local']);

        $this->app->forgetInstance(StorageManager::class);
        $this->app->make(StorageManager::class)
            ->register('local', new LocalStorageProvider(Storage::fake('local-import'), 'local'));
    }

    private function wav(string $name, int $kilobytes = 1): UploadedFile
    {
        // A real RIFF/WAVE header, so finfo reports audio from the bytes rather
        // than from the name.
        $header = 'RIFF'.pack('V', 36 + 1024).'WAVEfmt '.pack('VvvVVvv', 16, 1, 2, 44100, 176400, 4, 16).'data'.pack('V', 1024);

        return $this->realUpload($name, $header.str_repeat("\0", $kilobytes * 1024));
    }

    private function realUpload(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sanitube-bulk-');

        self::assertIsString($path);
        file_put_contents($path, $contents);

        $this->temporary[] = $path;

        return new UploadedFile($path, $name, null, null, test: true);
    }

    private function user(UserRole $role = UserRole::Admin): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }
}
