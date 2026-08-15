<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Events\AssetMissing;
use SaniTube\Assets\Events\AssetQuarantined;
use SaniTube\Assets\Events\AssetVerified;
use SaniTube\Assets\Exceptions\AssetStorageException;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Services\AssetStorageService;
use SaniTube\Assets\Services\AssetVerificationService;
use SaniTube\Storage\Exceptions\StorageOperationFailed;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * VERIFIED is the status the rest of the platform treats as proof.
 *
 * So the question these ask is not "does verification pass a good asset" but
 * "can VERIFIED ever be reached without the bytes being read back". Every
 * other test here is about telling the three failures apart, because they call
 * for very different responses: a missing object, a changed object, and a
 * provider that simply could not be reached.
 */
final class AssetVerificationTest extends TestCase
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

    #[Test]
    public function a_stored_asset_whose_bytes_are_intact_becomes_verified(): void
    {
        Event::fake([AssetVerified::class]);

        $asset = $this->storedAsset('a real master');

        $verified = $this->verification()->verify($asset);

        $this->assertSame(AssetStatus::Verified, $verified->status);
        $this->assertNotNull($verified->verified_at);

        Event::assertDispatched(AssetVerified::class);
    }

    #[Test]
    public function an_asset_whose_object_has_gone_is_marked_missing(): void
    {
        Event::fake([AssetMissing::class]);

        $asset = $this->storedAsset('a real master');

        // A lifecycle rule, a bucket swap, a restore that replaced less than
        // it removed.
        $this->store->delete($asset->path);

        $this->assertSame(AssetStatus::Missing, $this->verification()->verify($asset)->status);

        Event::assertDispatched(AssetMissing::class);
    }

    #[Test]
    public function an_asset_whose_bytes_changed_underneath_it_is_quarantined(): void
    {
        Event::fake([AssetQuarantined::class]);

        $asset = $this->storedAsset('the original master');

        // The worst case in the whole system: something rewrote a master.
        $this->store->put($asset->path, 'something else entirely');

        $this->assertSame(AssetStatus::Quarantined, $this->verification()->verify($asset)->status);

        Event::assertDispatched(AssetQuarantined::class);
    }

    #[Test]
    public function an_object_of_the_right_size_but_the_wrong_content_is_still_caught(): void
    {
        // Size alone would pass this. The checksum is the point.
        $asset = $this->storedAsset('aaaaaaaaaa');

        $this->store->put($asset->path, 'bbbbbbbbbb');

        $this->assertSame(AssetStatus::Quarantined, $this->verification()->verify($asset)->status);
    }

    #[Test]
    public function a_provider_that_cannot_be_reached_never_produces_a_verdict(): void
    {
        // An unreachable bucket says nothing about the asset. Recording
        // MISSING here would turn a network blip into a catalogue-wide
        // incident, and the damage would already be done by the time the
        // network came back.
        $asset = $this->storedAsset('a real master');

        $this->store->failWith('Connection timed out.');

        try {
            $this->verification()->verify($asset);
            $this->fail('An unreachable provider produced a verdict.');
        } catch (StorageOperationFailed) {
            // Expected.
        }

        $this->assertSame(AssetStatus::Stored, $asset->refresh()->status);
    }

    #[Test]
    public function a_storage_failure_never_writes_a_false_verified(): void
    {
        $asset = $this->storedAsset('a real master');
        $this->store->failWith('Connection timed out.');

        try {
            $this->verification()->verify($asset);
        } catch (StorageOperationFailed) {
            // Expected.
        }

        $this->assertNotSame(AssetStatus::Verified, $asset->refresh()->status);
        $this->assertNull($asset->verified_at);
    }

    #[Test]
    public function verification_re_run_on_a_verified_asset_confirms_it_again(): void
    {
        // Verification is not a one-off gate. Re-running it is how a bucket
        // that changed under the catalogue is found.
        $asset = $this->storedAsset('a real master');

        $verified = $this->verification()->verify($asset);
        $this->assertSame(AssetStatus::Verified, $verified->status);

        $this->store->put($verified->path, 'tampered');

        $this->assertSame(AssetStatus::Quarantined, $this->verification()->verify($verified)->status);
    }

    #[Test]
    public function a_pending_asset_cannot_be_verified(): void
    {
        // There is nothing to read back, so there is nothing to conclude.
        $asset = $this->storage()->register(AssetKind::AudioMaster, 'master.wav');

        $this->expectException(AssetStorageException::class);
        $this->expectExceptionMessage('nothing to verify');

        $this->verification()->verify($asset);
    }

    #[Test]
    public function a_batch_reports_what_each_asset_turned_out_to_be(): void
    {
        $intact = $this->storedAsset('intact');
        $gone = $this->storedAsset('gone');
        $this->store->delete($gone->path);

        $results = $this->verification()->verifyAll([$intact, $gone]);

        $this->assertSame(AssetStatus::Verified, $results[$intact->uuid]);
        $this->assertSame(AssetStatus::Missing, $results[$gone->uuid]);
    }

    // ----------------------------------------------------------- helpers

    private function storedAsset(string $contents): Asset
    {
        $asset = $this->storage()->register(AssetKind::AudioMaster, 'master.wav');

        $stream = fopen('php://memory', 'r+');

        if ($stream === false) {
            $this->fail('Could not open a memory stream.');
        }

        fwrite($stream, $contents);
        rewind($stream);

        return $this->storage()->store($asset, $stream);
    }

    private function storage(): AssetStorageService
    {
        return $this->app->make(AssetStorageService::class);
    }

    private function verification(): AssetVerificationService
    {
        return $this->app->make(AssetVerificationService::class);
    }
}
