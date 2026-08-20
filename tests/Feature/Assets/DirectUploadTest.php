<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Observers\AssetObserver;
use SaniTube\Assets\Services\AssetStorageService;
use SaniTube\Assets\Services\BeginDirectUpload;
use SaniTube\Assets\Storage\AssetObjectKeys;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Models\AuditEvent;
use SaniTube\Deduplication\Enums\DuplicateLevel;
use SaniTube\Deduplication\Models\DuplicateRelation;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Storage\Contracts\SupportsDirectUpload;
use SaniTube\Storage\Providers\LocalStorageProvider;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * Uploading a master without it passing through this application.
 *
 * A master is routinely hundreds of megabytes. On the shared cPanel accounts
 * this platform targets, `upload_max_filesize`, `post_max_size`,
 * `memory_limit` and `max_execution_time` would all have to be generous at
 * once for that to work through PHP, and at least one of them never is. So the
 * browser writes to object storage directly.
 *
 * **The claim these tests exist to defend is that giving up the data path is
 * not giving up authority.** The browser chooses the bytes. It does not get to
 * choose where they go, what they are called, how big they are allowed to be,
 * what they hash to, or whether they were acceptable — and the completion call
 * it makes is a request to go and look, not a report to be believed.
 */
final class DirectUploadTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryStorageProvider $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new InMemoryStorageProvider('memory');

        config(['storage.default' => 'memory']);

        $this->app->make(StorageManager::class)->register('memory', $this->store);
    }

    // ------------------------------------------------------- authorisation

    #[Test]
    public function a_member_cannot_start_an_upload(): void
    {
        $this->actingAs($this->user(UserRole::Member))
            ->postJson('/assets/uploads', ['kind' => AssetKind::AudioMaster->value, 'filename' => 'master.wav'])
            ->assertForbidden();

        $this->assertSame(0, Asset::query()->count());
    }

    #[Test]
    public function starting_an_upload_registers_the_asset_before_the_url_exists(): void
    {
        // The order matters: the key the URL may write to has to be one this
        // application chose. Minting first would be handing out a capability
        // for a location nothing had claimed.
        $response = $this->actingAs($this->user())
            ->postJson('/assets/uploads', ['kind' => AssetKind::AudioMaster->value, 'filename' => 'master.wav'])
            ->assertOk();

        $asset = Asset::query()->firstOrFail();

        $this->assertSame(AssetStatus::Pending, $asset->status);
        $this->assertSame($asset->uuid, $response->json('asset'));
        $this->assertNotEmpty($response->json('upload.url'));
        $this->assertNotEmpty($response->json('upload.expires_at'));
    }

    #[Test]
    public function the_capability_is_not_written_down_anywhere(): void
    {
        // The signature in that URL *is* the permission. A response a proxy
        // may cache outlives the tab that asked for it.
        $response = $this->actingAs($this->user())
            ->postJson('/assets/uploads', ['kind' => AssetKind::AudioMaster->value, 'filename' => 'master.wav']);

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        // And not into the audit log either, where it would sit for a year.
        //
        // This holds for a stronger reason than call-site discipline: AUDIT-001's
        // redaction refuses any value containing a URL, whatever key it arrives
        // under. A mutation that deliberately logs the URL here still cannot get
        // it into the row — which is why that mutation was withdrawn rather than
        // chased with a second assertion. The guarantee is tested where it lives.
        $event = AuditEvent::query()->where('action', AuditAction::AssetUploadStarted->value)->firstOrFail();

        foreach ((array) $event->getAttributes() as $column => $value) {
            $this->assertStringNotContainsString('upload=1', (string) $value, sprintf('[%s] holds the upload URL.', $column));
            $this->assertStringNotContainsString('staging', (string) $value, sprintf('[%s] holds a storage key.', $column));
        }
    }

    // ------------------------------------------------- what the server owns

    #[Test]
    public function the_server_measures_what_arrived_rather_than_believing_the_browser(): void
    {
        $asset = $this->begin();
        $contents = 'the actual bytes that landed in staging';

        $this->store->put($this->stagingKeyFor($asset), $contents);

        // The completion call carries no size, no checksum and no media type.
        // There is nothing in it to trust.
        $this->actingAs($this->user())
            ->postJson('/assets/uploads/'.$asset->uuid.'/complete')
            ->assertOk();

        $stored = $asset->refresh();

        $this->assertSame(AssetStatus::Stored, $stored->status);
        $this->assertSame(hash('sha256', $contents), $stored->sha256);
        $this->assertSame(strlen($contents), $stored->byte_size);
    }

    #[Test]
    public function an_upload_that_never_arrived_is_refused(): void
    {
        // The browser said it was done and there is nothing there. From the
        // server's side that is the same event as an empty upload.
        $asset = $this->begin();

        $this->actingAs($this->user())
            ->postJson('/assets/uploads/'.$asset->uuid.'/complete')
            ->assertStatus(422)
            ->assertJson(['code' => 'UPLOAD_NOT_ACCEPTABLE']);

        $this->assertSame(AssetStatus::Pending, $asset->refresh()->status);
    }

    #[Test]
    public function an_object_over_the_limit_is_discarded_rather_than_kept(): void
    {
        // A presigned PUT cannot express a maximum, so this is the only thing
        // standing between the platform and an object somebody decided should
        // be enormous. It is enforced after the fact because that is the only
        // place it can be.
        config(['assets.max_upload_bytes.'.AssetKind::AudioMaster->value => 16]);

        $asset = $this->begin();
        $staging = $this->stagingKeyFor($asset);

        $this->store->put($staging, str_repeat('x', 64));

        $this->actingAs($this->user())
            ->postJson('/assets/uploads/'.$asset->uuid.'/complete')
            ->assertStatus(422);

        $this->assertSame(AssetStatus::Pending, $asset->refresh()->status);
        $this->assertFalse($this->store->exists($staging), 'The oversized object was left in staging.');
    }

    #[Test]
    public function a_completion_call_cannot_be_replayed_into_a_second_asset(): void
    {
        $asset = $this->begin();

        $this->store->put($this->stagingKeyFor($asset), 'audio');

        $this->actingAs($this->user())->postJson('/assets/uploads/'.$asset->uuid.'/complete')->assertOk();
        $this->actingAs($this->user())->postJson('/assets/uploads/'.$asset->uuid.'/complete')->assertOk();

        $this->assertSame(1, Asset::query()->count());
        $this->assertSame(AssetStatus::Stored, $asset->refresh()->status);
    }

    #[Test]
    public function identical_bytes_are_reported_as_already_held(): void
    {
        // The interface says so before somebody waits for an analysis of a
        // file the platform already has. The content identity rules are the
        // ordinary ones — this path does not get its own.
        $first = $this->begin();
        $this->store->put($this->stagingKeyFor($first), 'the very same master');
        $this->actingAs($this->user())->postJson('/assets/uploads/'.$first->uuid.'/complete');

        $second = $this->begin('copy.wav');
        $this->store->put($this->stagingKeyFor($second), 'the very same master');

        $this->actingAs($this->user())
            ->postJson('/assets/uploads/'.$second->uuid.'/complete')
            ->assertOk()
            ->assertJson(['duplicate' => true]);
    }

    // ---------------------------------------------------- provider capability

    #[Test]
    public function a_provider_that_cannot_mint_upload_urls_refuses_rather_than_pretends(): void
    {
        // The real local provider, not a double told to refuse. A double that
        // declares the capability and then throws would be testing the very
        // thing the capability interface exists to make impossible.
        config(['storage.default' => 'local-only']);

        // The manager reads the default once, when it is built. Forgetting the
        // instance is how a test changes which provider is in use rather than
        // which providers exist.
        $this->app->forgetInstance(StorageManager::class);

        $this->app->make(StorageManager::class)->register(
            'local-only',
            new LocalStorageProvider(Storage::disk('local'), 'local-only'),
        );

        $this->assertNotInstanceOf(SupportsDirectUpload::class, $this->app->make(StorageManager::class)->default());

        $this->actingAs($this->user())
            ->postJson('/assets/uploads', ['kind' => AssetKind::AudioMaster->value, 'filename' => 'master.wav'])
            ->assertStatus(422)
            ->assertJson(['code' => 'DIRECT_UPLOAD_UNSUPPORTED']);
    }

    #[Test]
    public function two_files_with_the_same_name_do_not_collide(): void
    {
        // The filename is metadata. The key comes from the asset's uuid, which
        // is what lets a library hold four thousand files called master.wav.
        $first = $this->begin('song.mp3');
        $second = $this->begin('song.mp3');

        $this->assertNotSame($this->stagingKeyFor($first), $this->stagingKeyFor($second));
        $this->assertNotSame($first->path, $second->path);
        $this->assertSame('song.mp3', $first->original_filename);
        $this->assertSame('song.mp3', $second->original_filename);
    }

    /**
     * The production path, asserted end to end.
     *
     * A direct upload never passes through the ingestion service that other
     * routes go through, so nothing about it being analysed follows from those
     * tests. What makes it work is that {@see AssetObserver}
     * raises `AssetStored` on the row itself, whichever code wrote it — and
     * that is precisely the kind of claim that stays true until somebody moves
     * the event into the service where it looks like it belongs.
     */
    #[Test]
    public function a_direct_upload_enters_the_deduplication_pipeline(): void
    {
        $storage = $this->app->make(AssetStorageService::class);
        $first = $storage->register(AssetKind::AudioMaster, 'already-here.wav');
        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, 'identical master bytes');
        rewind($stream);
        $storage->store($first, $stream);

        $asset = $this->begin();
        $this->store->put($this->stagingKeyFor($asset), 'identical master bytes');

        $this->actingAs($this->user())
            ->postJson('/assets/uploads/'.$asset->uuid.'/complete')
            ->assertOk();

        $relation = DuplicateRelation::query()->first();

        self::assertInstanceOf(
            DuplicateRelation::class,
            $relation,
            'A direct upload did not reach the deduplication pipeline.',
        );
        self::assertSame(DuplicateLevel::ExactDuplicate, $relation->level);
    }

    // ------------------------------------------------------------ fixtures

    private function begin(string $filename = 'master.wav'): Asset
    {
        $started = $this->app->make(BeginDirectUpload::class)->for(AssetKind::AudioMaster, $filename);

        /** @var Asset $asset */
        $asset = $started['asset'];

        return $asset;
    }

    private function stagingKeyFor(Asset $asset): string
    {
        return $this->app->make(AssetObjectKeys::class)->staging($asset->uuid, $asset->original_filename);
    }

    private function user(UserRole $role = UserRole::Owner): User
    {
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => 'a-long-enough-passphrase',
        ]);

        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user;
    }
}
