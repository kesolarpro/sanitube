<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Storage\Providers\LocalStorageProvider;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * UPL-001 — a file gets into SaniTube from a browser.
 *
 * The product-path audit found that no `<input type="file">` existed anywhere
 * in the application. Neither audio nor artwork could enter from a browser, the
 * release cover picker was permanently empty on a fresh installation, and the
 * ingestion screen only imported from a storage prefix — which assumes somebody
 * already put the files there by FTP.
 *
 * **These tests go through the HTTP route, not the service.** That is the whole
 * point of the ticket: the backend for direct upload already existed and was
 * tested, and nothing called it. A test that exercised `AssetStorageService`
 * would have passed before this ticket too.
 *
 * **And they use real `UploadedFile`s, not `UploadedFile::fake()`.** That is not
 * fastidiousness. `Illuminate\Http\Testing\File::getMimeType()` derives the type
 * from the *filename*, so `createWithContent('master.wav', 'this is not audio')`
 * reports `audio/wav`. A test built on it would have passed against an
 * implementation that trusted the extension — which is the exact thing the
 * server must not do, and the exact bug this file is meant to catch. A real
 * `UploadedFile` reads the bytes and reports `text/plain`.
 */
final class BrowserUploadTest extends TestCase
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
            @unlink($path);
        }

        $this->temporary = [];

        parent::tearDown();
    }

    // ------------------------------------------------------------ the screen

    #[Test]
    public function the_upload_screen_is_reachable_and_behind_authentication(): void
    {
        $this->get('/assets/upload')->assertRedirect('/login');

        $this->actingAs($this->user())->get('/assets/upload')->assertOk();
    }

    #[Test]
    public function a_read_only_member_cannot_reach_the_upload_screen_or_post_to_it(): void
    {
        // The hidden button is presentation; the route middleware is the check.
        $member = $this->user(UserRole::Member);

        $this->actingAs($member)->get('/assets/upload')->assertForbidden();

        $this->actingAs($member)
            ->post('/assets/uploads/relay', [
                'kind' => AssetKind::AudioMaster->value,
                'file' => $this->wav('master.wav'),
            ])
            ->assertForbidden();

        $this->assertSame(0, Asset::query()->count());
    }

    #[Test]
    public function the_screen_reports_what_this_installation_can_actually_do(): void
    {
        // Measured, not assumed. The in-memory provider supports direct upload;
        // `LocalStorageProvider` — the shipped default — does not, and the
        // screen has to tell the truth about which one it is on.
        $page = $this->actingAs($this->user())->get('/assets/upload');

        $upload = $page->viewData('page')['props']['upload'];

        $this->assertTrue($upload['direct']);
        $this->assertSame(
            [AssetKind::AudioMaster->value, AssetKind::Artwork->value],
            array_column($upload['kinds'], 'value'),
        );
    }

    #[Test]
    public function a_relayed_installation_reports_the_lower_of_the_two_ceilings(): void
    {
        // An operator who raised SANITUBE_MAX_MASTER_BYTES to two gigabytes and
        // left post_max_size at eight megabytes has an installation that
        // refuses every master. A screen advertising the two-gigabyte figure
        // would be lying in the one place they would look.
        config(['assets.max_upload_bytes.'.AssetKind::AudioMaster->value => 2_000_000_000]);

        $upload = $this->screenFor(direct: false);

        /** @var list<array<string, mixed>> $kinds */
        $kinds = $upload['kinds'];
        $master = null;

        foreach ($kinds as $row) {
            if ($row['value'] === AssetKind::AudioMaster->value) {
                $master = $row;
            }
        }

        $this->assertIsArray($master);
        $this->assertSame(2_000_000_000, $master['maximum_bytes']);

        if ($upload['php_post_limit_bytes'] > 0 && $upload['php_post_limit_bytes'] < 2_000_000_000) {
            $this->assertSame($upload['php_post_limit_bytes'], $master['effective_maximum_bytes']);
            $this->assertTrue($master['limited_by_php'], 'The binding limit must be named.');
        }
    }

    // ------------------------------------------------- the upload itself

    #[Test]
    public function a_master_uploaded_from_a_browser_becomes_a_verified_asset(): void
    {
        // The claim of this ticket, end to end through the real route.
        $response = $this->actingAs($this->user())
            ->post('/assets/uploads/relay', [
                'kind' => AssetKind::AudioMaster->value,
                'file' => $this->wav('master.wav'),
            ])
            ->assertOk();

        $uuid = $response->json('asset');
        $this->assertIsString($uuid);

        $asset = Asset::query()->where('uuid', $uuid)->firstOrFail();

        $this->assertSame(AssetKind::AudioMaster, $asset->kind);
        // Verified, not merely stored: the measurement, analysis and
        // deduplication listeners all fire on verification, so an asset left at
        // STORED would sit untouched for ever.
        $this->assertSame(AssetStatus::Verified, $asset->status);
        $this->assertSame('master.wav', $asset->original_filename);
        $this->assertGreaterThan(0, (int) $asset->byte_size);
        $this->assertNotNull($asset->sha256);
    }

    #[Test]
    public function artwork_can_be_uploaded_so_a_release_cover_can_exist_at_all(): void
    {
        // Before this, the cover picker offered only existing ARTWORK assets
        // and there was no way to create one. On a fresh installation it was
        // empty, permanently.
        $response = $this->actingAs($this->user())
            ->post('/assets/uploads/relay', [
                'kind' => AssetKind::Artwork->value,
                'file' => $this->jpeg('cover.jpg'),
            ])
            ->assertOk();

        $asset = Asset::query()->where('uuid', $response->json('asset'))->firstOrFail();

        $this->assertSame(AssetKind::Artwork, $asset->kind);
        $this->assertSame(AssetStatus::Verified, $asset->status);
    }

    #[Test]
    public function the_same_bytes_twice_are_reported_as_already_held(): void
    {
        $user = $this->user();

        $first = $this->actingAs($user)
            ->post('/assets/uploads/relay', ['kind' => AssetKind::AudioMaster->value, 'file' => $this->wav('a.wav')])
            ->assertOk();

        $second = $this->actingAs($user)
            ->post('/assets/uploads/relay', ['kind' => AssetKind::AudioMaster->value, 'file' => $this->wav('b.wav')])
            ->assertOk();

        $this->assertFalse($first->json('duplicate'));
        // So the screen can say so before somebody waits on the analysis of a
        // file the platform already has.
        $this->assertTrue($second->json('duplicate'));
    }

    // ----------------------------------------------------- what it refuses

    #[Test]
    public function the_media_type_is_read_from_the_bytes_and_not_from_the_name(): void
    {
        // A `.wav` holding something else is a real thing, and the name is the
        // uploader's word for it.
        $this->actingAs($this->user())
            ->post('/assets/uploads/relay', [
                'kind' => AssetKind::AudioMaster->value,
                // Named .wav, containing text. A real upload, so the server's
                // finfo sees text/plain — the fake helper would have said
                // audio/wav and the test would have proved nothing.
                'file' => $this->realUpload('master.wav', 'this is not audio'),
            ])
            ->assertStatus(422)
            ->assertJson(['code' => 'MEDIA_TYPE_NOT_ACCEPTED']);

        $this->assertSame(0, Asset::query()->count());
    }

    #[Test]
    public function an_image_offered_as_a_master_is_refused(): void
    {
        $this->actingAs($this->user())
            ->post('/assets/uploads/relay', [
                'kind' => AssetKind::AudioMaster->value,
                'file' => $this->jpeg('cover.jpg'),
            ])
            ->assertStatus(422)
            ->assertJson(['code' => 'MEDIA_TYPE_NOT_ACCEPTED']);
    }

    #[Test]
    public function a_kind_the_platform_produces_itself_cannot_be_uploaded(): void
    {
        // A derivative, a preview and a stem are made by SaniTube from a
        // master. Accepting one directly would create audio whose lineage is a
        // claim rather than a record.
        foreach ([AssetKind::AudioDerivative, AssetKind::Preview, AssetKind::Stem] as $kind) {
            $this->actingAs($this->user())
                ->post('/assets/uploads/relay', ['kind' => $kind->value, 'file' => $this->wav('x.wav')])
                ->assertStatus(422)
                ->assertJson(['code' => 'KIND_NOT_UPLOADABLE']);
        }

        $this->assertSame(0, Asset::query()->count());
    }

    #[Test]
    public function a_file_over_the_ceiling_is_refused_before_an_asset_exists(): void
    {
        config(['assets.max_upload_bytes.'.AssetKind::AudioMaster->value => 1024]);

        $this->actingAs($this->user())
            ->post('/assets/uploads/relay', [
                'kind' => AssetKind::AudioMaster->value,
                'file' => $this->wav('big.wav', 64),
            ])
            ->assertStatus(422)
            ->assertJson(['code' => 'FILE_TOO_LARGE']);

        // Nothing registered. The streaming cap would also catch it, but only
        // after a row exists for a file that was never going to be kept.
        $this->assertSame(0, Asset::query()->count());
    }

    #[Test]
    public function an_empty_accepted_list_means_any_type_rather_than_none(): void
    {
        // Clearing a list expresses "I do not want this rule", never "refuse
        // everything". The opposite reading would make an installation that
        // cleared it unable to upload anything at all.
        config(['assets.accepted_upload_types.'.AssetKind::AudioMaster->value => []]);

        $this->actingAs($this->user())
            ->post('/assets/uploads/relay', [
                'kind' => AssetKind::AudioMaster->value,
                'file' => $this->realUpload('master.wav', 'this is not audio'),
            ])
            ->assertOk();
    }

    #[Test]
    public function an_unknown_kind_is_rejected_by_validation(): void
    {
        $this->actingAs($this->user())
            ->postJson('/assets/uploads/relay', ['kind' => 'NOT_A_KIND'])
            ->assertStatus(422);
    }

    #[Test]
    public function no_storage_location_is_ever_returned_to_the_browser(): void
    {
        $response = $this->actingAs($this->user())
            ->post('/assets/uploads/relay', [
                'kind' => AssetKind::AudioMaster->value,
                'file' => $this->wav('master.wav'),
            ])
            ->assertOk();

        $asset = Asset::query()->where('uuid', $response->json('asset'))->firstOrFail();
        $body = (string) $response->getContent();

        // Each named and asserted non-empty first, so the loop cannot quietly
        // check fewer things than it claims.
        foreach (['path' => $asset->path, 'disk' => $asset->disk, 'checksum' => $asset->sha256] as $label => $secret) {
            $this->assertNotEmpty($secret, $label.' is empty, so asserting on it proves nothing.');
            $this->assertStringNotContainsString((string) $secret, $body, $label.' leaked to the browser.');
        }
    }

    // ----------------------------------------------------------- fixtures

    /**
     * A file whose *bytes* are a WAV, because the server reads the bytes.
     */
    private function wav(string $name, int $kilobytes = 4): UploadedFile
    {
        // A real RIFF/WAVE header, so that finfo reports audio/x-wav from the
        // bytes rather than from the name.
        $header = 'RIFF'.pack('V', 36 + 1024).'WAVEfmt '.pack('VvvVVvv', 16, 1, 2, 44100, 176400, 4, 16).'data'.pack('V', 1024);

        return $this->realUpload($name, $header.str_repeat("\0", $kilobytes * 1024));
    }

    private function jpeg(string $name): UploadedFile
    {
        $image = imagecreatetruecolor(64, 64);

        ob_start();
        imagejpeg($image);
        $bytes = (string) ob_get_clean();

        imagedestroy($image);

        return $this->realUpload($name, $bytes);
    }

    /**
     * A genuine upload, whose media type is read from its content.
     *
     * Never `UploadedFile::fake()` where the type matters — see the note at the
     * top of this file.
     */
    private function realUpload(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sanitube-upl-');

        self::assertIsString($path);
        file_put_contents($path, $contents);

        $this->temporary[] = $path;

        return new UploadedFile($path, $name, null, null, test: true);
    }

    /**
     * @return array<string, mixed>
     */
    private function screenFor(bool $direct): array
    {
        if (! $direct) {
            // The real LocalStorageProvider, not a double. It is the shipped
            // default and the only option on a host without object storage, and
            // it is precisely the provider that cannot take a direct write —
            // so a stand-in here would be testing something that does not ship.
            Storage::fake('relayed-disk');

            config(['storage.default' => 'relayed']);

            // Rebuilt, not just reconfigured: the manager reads the default
            // provider name once, when it is constructed. Setting the config
            // afterwards would leave it still pointing at the in-memory
            // provider, and the test would quietly assert the direct path.
            $this->app->forgetInstance(StorageManager::class);
            $this->app->make(StorageManager::class)->register(
                'relayed',
                new LocalStorageProvider(Storage::disk('relayed-disk'), 'relayed'),
            );
        }

        $page = $this->actingAs($this->user())->get('/assets/upload');

        /** @var array<string, mixed> $upload */
        $upload = $page->viewData('page')['props']['upload'];

        return $upload;
    }

    private function user(UserRole $role = UserRole::Admin): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }
}
