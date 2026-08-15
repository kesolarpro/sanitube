<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Events\AssetDuplicateDetected;
use SaniTube\Assets\Events\AssetStored;
use SaniTube\Assets\Exceptions\AssetIntegrityException;
use SaniTube\Assets\Exceptions\AssetStorageException;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Services\AssetStorageService;
use SaniTube\Assets\Storage\AssetObjectKeys;
use SaniTube\Storage\Providers\LocalStorageProvider;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * PENDING → STORED, and every way that must be allowed to fail.
 */
final class AssetStorageWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryStorageProvider $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new InMemoryStorageProvider('memory');

        // Before the manager is resolved: it reads the configured default once,
        // at construction.
        config(['storage.default' => 'memory']);

        $manager = $this->app->make(StorageManager::class);
        $manager->register('memory', $this->store);
        $manager->register('local', new LocalStorageProvider(Storage::fake('assets'), 'local'));
    }

    // ---------------------------------------------------------- the path

    #[Test]
    public function registering_an_asset_reserves_a_key_and_writes_nothing(): void
    {
        $asset = $this->service()->register(AssetKind::AudioMaster, 'Final Master.wav');

        $this->assertSame(AssetStatus::Pending, $asset->status);
        $this->assertSame('masters/'.$asset->uuid.'/original.wav', $asset->path);
        $this->assertSame('Final Master.wav', $asset->original_filename);

        // A pending asset has no checksum because nothing has been hashed.
        $this->assertNull($asset->sha256);
        $this->assertNull($asset->byte_size);

        $this->assertSame([], $this->store->keys(), 'Registration wrote to storage.');
    }

    #[Test]
    public function an_upload_reaches_stored_with_the_checksum_of_what_is_in_storage(): void
    {
        Event::fake([AssetStored::class]);

        $contents = str_repeat('RIFFWAVE', 512);
        $asset = $this->service()->register(AssetKind::AudioMaster, 'master.wav');

        $stored = $this->service()->store($asset, $this->streamOf($contents));

        $this->assertSame(AssetStatus::Stored, $stored->status);
        $this->assertSame(hash('sha256', $contents), $stored->sha256);
        $this->assertSame(strlen($contents), $stored->byte_size);
        $this->assertNotNull($stored->stored_at);

        // The bytes are at the canonical key and nowhere else.
        $this->assertSame([$stored->path], $this->store->keys());
        $this->assertSame($contents, $this->store->get($stored->path));

        Event::assertDispatched(AssetStored::class);
    }

    #[Test]
    public function nothing_is_left_in_staging_after_a_successful_upload(): void
    {
        $asset = $this->service()->register(AssetKind::AudioMaster, 'master.wav');
        $this->service()->store($asset, $this->streamOf('audio'));

        $this->assertSame([], $this->store->files(AssetObjectKeys::STAGING_PREFIX));
    }

    #[Test]
    public function the_stored_type_is_sniffed_from_the_bytes_not_taken_on_trust(): void
    {
        // The declared content type is attacker-controlled and is never
        // recorded. A .wav full of text is text.
        $asset = $this->service()->register(AssetKind::AudioMaster, 'not-really-audio.wav');

        $stored = $this->service()->store($asset, $this->streamOf('this is plainly text, not audio'));

        $this->assertSame('text/plain', $stored->mime_type);
    }

    // -------------------------------------------------------- refusals

    #[Test]
    public function an_upload_whose_checksum_does_not_match_is_discarded(): void
    {
        $asset = $this->service()->register(AssetKind::AudioMaster, 'master.wav');

        try {
            $this->service()->store($asset, $this->streamOf('the real bytes'), expectedSha256: hash('sha256', 'different bytes'));
            $this->fail('A mismatched checksum was accepted.');
        } catch (AssetStorageException $exception) {
            $this->assertStringContainsString('does not match the expected checksum', $exception->getMessage());
        }

        $this->assertSame(AssetStatus::Pending, $asset->refresh()->status);
        $this->assertNull($asset->sha256);
        $this->assertSame([], $this->store->keys(), 'A rejected upload was left in storage.');
    }

    #[Test]
    public function an_upload_of_the_wrong_size_is_discarded(): void
    {
        $asset = $this->service()->register(AssetKind::AudioMaster, 'master.wav');

        $this->expectException(AssetStorageException::class);
        $this->expectExceptionMessage('not the expected');

        try {
            $this->service()->store($asset, $this->streamOf('five!'), expectedByteSize: 999);
        } finally {
            $this->assertSame(AssetStatus::Pending, $asset->refresh()->status);
            $this->assertSame([], $this->store->keys());
        }
    }

    #[Test]
    public function an_upload_past_the_size_limit_is_cut_off_and_discarded(): void
    {
        // Enforced while the bytes are streaming: a client must not be able to
        // fill a bucket first and be rejected second.
        config(['assets.max_upload_bytes.'.AssetKind::Artwork->value => 1024]);

        $asset = $this->service()->register(AssetKind::Artwork, 'huge.jpg');

        $this->expectException(AssetStorageException::class);
        $this->expectExceptionMessage('exceeds the ARTWORK limit');

        try {
            $this->service()->store($asset, $this->streamOf(str_repeat('x', 10_000)));
        } finally {
            $this->assertSame([], $this->store->keys());
        }
    }

    #[Test]
    public function an_upload_of_exactly_the_size_limit_is_accepted(): void
    {
        // The boundary matters as much as the limit: rejecting a file of
        // exactly the permitted size would be a bug nobody notices for months.
        config(['assets.max_upload_bytes.'.AssetKind::Artwork->value => 1024]);

        $asset = $this->service()->register(AssetKind::Artwork, 'exact.jpg');

        $stored = $this->service()->store($asset, $this->streamOf(str_repeat('x', 1024)));

        $this->assertSame(1024, $stored->byte_size);
    }

    #[Test]
    public function an_empty_upload_is_refused(): void
    {
        $asset = $this->service()->register(AssetKind::AudioMaster, 'nothing.wav');

        $this->expectException(AssetStorageException::class);
        $this->expectExceptionMessage('contained no bytes');

        $this->service()->store($asset, $this->streamOf(''));
    }

    #[Test]
    public function a_storage_failure_leaves_the_asset_pending_rather_than_half_recorded(): void
    {
        $asset = $this->service()->register(AssetKind::AudioMaster, 'master.wav');

        $this->store->failWith('The endpoint went away mid-upload.');

        try {
            $this->service()->store($asset, $this->streamOf('audio'));
            $this->fail('A failing provider produced a stored asset.');
        } catch (\Throwable) {
            // The failure itself is the provider's; what matters is the state
            // it left behind.
        }

        $asset->refresh();

        $this->assertSame(AssetStatus::Pending, $asset->status);
        $this->assertNull($asset->sha256);
        $this->assertNull($asset->stored_at);
    }

    // ------------------------------------------------------ idempotency

    #[Test]
    public function repeating_a_successful_upload_changes_nothing(): void
    {
        $contents = 'the same bytes';
        $asset = $this->service()->register(AssetKind::AudioMaster, 'master.wav');

        $first = $this->service()->store($asset, $this->streamOf($contents));
        $storedAt = $first->stored_at;

        $second = $this->service()->store($first, $this->streamOf($contents), expectedSha256: hash('sha256', $contents));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(AssetStatus::Stored, $second->status);
        $this->assertEquals($storedAt, $second->stored_at);
        $this->assertSame([$first->path], $this->store->keys());
    }

    #[Test]
    public function retrying_a_stored_asset_with_different_bytes_is_refused(): void
    {
        // Idempotency must not become a way to replace a master.
        $asset = $this->service()->register(AssetKind::AudioMaster, 'master.wav');
        $stored = $this->service()->store($asset, $this->streamOf('the original master'));

        $this->expectException(AssetStorageException::class);
        $this->expectExceptionMessage('already exists with different content');

        $this->service()->store($stored, $this->streamOf('a replacement'), expectedSha256: hash('sha256', 'a replacement'));
    }

    // -------------------------------------------------------- duplicates

    #[Test]
    public function identical_bytes_are_recorded_as_a_duplicate_rather_than_rejected(): void
    {
        Event::fake([AssetDuplicateDetected::class]);

        $contents = 'the very same master';

        $first = $this->service()->store(
            $this->service()->register(AssetKind::AudioMaster, 'master.wav'),
            $this->streamOf($contents),
        );

        $second = $this->service()->store(
            $this->service()->register(AssetKind::AudioMaster, 'master-copy.wav'),
            $this->streamOf($contents),
        );

        $this->assertSame(AssetStatus::Stored, $second->status, 'A duplicate upload was refused.');
        $this->assertSame($first->id, $second->duplicate_of_asset_id);
        $this->assertSame($first->sha256, $second->sha256);

        // Provenance is preserved on both sides: two assets, two objects.
        $this->assertNull($first->refresh()->duplicate_of_asset_id);
        $this->assertCount(2, $this->store->keys());

        Event::assertDispatched(AssetDuplicateDetected::class);
    }

    #[Test]
    public function duplicate_detection_can_be_switched_off(): void
    {
        config(['assets.duplicates.detect' => false]);

        $contents = 'identical';

        $this->service()->store($this->service()->register(AssetKind::AudioMaster, 'a.wav'), $this->streamOf($contents));
        $second = $this->service()->store($this->service()->register(AssetKind::AudioMaster, 'b.wav'), $this->streamOf($contents));

        $this->assertNull($second->duplicate_of_asset_id);
    }

    // -------------------------------------------------- inherited rules

    #[Test]
    public function the_arch_002_immutability_rule_still_holds_over_a_stored_asset(): void
    {
        $asset = $this->service()->store(
            $this->service()->register(AssetKind::AudioMaster, 'master.wav'),
            $this->streamOf('audio'),
        );

        $this->expectException(AssetIntegrityException::class);
        $this->expectExceptionMessage('is immutable');

        $asset->update(['path' => 'masters/elsewhere/original.wav']);
    }

    #[Test]
    public function an_asset_cannot_reach_stored_without_the_measurements_of_its_bytes(): void
    {
        // The invariant that makes the nullable columns safe.
        $asset = $this->service()->register(AssetKind::AudioMaster, 'master.wav');

        $this->expectException(AssetIntegrityException::class);
        $this->expectExceptionMessage('without sha256');

        $asset->update(['status' => AssetStatus::Stored]);
    }

    #[Test]
    public function an_asset_cannot_be_forced_to_verified_with_no_bytes_anywhere(): void
    {
        $asset = $this->service()->register(AssetKind::AudioMaster, 'master.wav');

        $this->expectException(AssetIntegrityException::class);

        $asset->update(['status' => AssetStatus::Verified]);
    }

    // ------------------------------------------------------------ access

    #[Test]
    public function an_asset_is_handed_out_only_through_an_expiring_url(): void
    {
        $asset = $this->service()->store(
            $this->service()->register(AssetKind::AudioMaster, 'master.wav'),
            $this->streamOf('audio'),
        );

        $url = $this->service()->temporaryUrl($asset, ttlSeconds: 60);

        $this->assertTrue($this->store->isTemporaryUrlValid($url));
        $this->assertFalse($this->store->isTemporaryUrlValid($url, now()->addMinutes(2)));
    }

    #[Test]
    public function the_service_offers_no_way_to_mint_a_permanent_url(): void
    {
        // Checked mechanically rather than by convention: a `url()` here would
        // be a permanent address for a master, and nobody would notice it
        // arrive.
        $methods = get_class_methods(AssetStorageService::class);

        $this->assertNotContains('url', $methods);
        $this->assertNotContains('publicUrl', $methods);
        $this->assertContains('temporaryUrl', $methods);
    }

    #[Test]
    public function an_asset_can_be_streamed_back_out(): void
    {
        $asset = $this->service()->store(
            $this->service()->register(AssetKind::AudioMaster, 'master.wav'),
            $this->streamOf('audio bytes'),
        );

        $stream = $this->service()->readStream($asset);

        $this->assertSame('audio bytes', stream_get_contents($stream));
        fclose($stream);
    }

    #[Test]
    public function the_workflow_behaves_the_same_on_a_local_disk(): void
    {
        // The cPanel baseline: no object storage at all.
        $contents = str_repeat('local audio', 100);
        $asset = $this->service()->register(AssetKind::AudioMaster, 'master.wav', provider: 'local');

        $stored = $this->service()->store($asset, $this->streamOf($contents));

        $this->assertSame('local', $stored->disk);
        $this->assertSame(AssetStatus::Stored, $stored->status);
        $this->assertSame(hash('sha256', $contents), $stored->sha256);
        Storage::disk('assets')->assertExists($stored->path);
    }

    // ----------------------------------------------------------- helpers

    private function service(): AssetStorageService
    {
        return $this->app->make(AssetStorageService::class);
    }

    /**
     * @return resource
     */
    private function streamOf(string $contents)
    {
        $stream = fopen('php://memory', 'r+');

        if ($stream === false) {
            $this->fail('Could not open a memory stream.');
        }

        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }
}
