<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Exceptions\AssetIntegrityException;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Services\RelocateAssets;
use SaniTube\Storage\Providers\LocalStorageProvider;
use SaniTube\Storage\StorageManager;
use Tests\TestCase;

/**
 * Location may move under proof; identity never. (ADR-0022)
 *
 * The catalogue migration from local disk to object storage hinges on one
 * carefully split invariant: sha256, size, path and kind stay frozen
 * forever, and `disk` may change through exactly one path — copy, verify
 * the *target* against what the asset has always claimed, then a sanctioned
 * save the observer consumes. These tests hold the split from both sides:
 * the sanctioned path works, and the unsanctioned one is exactly as frozen
 * as it was before ADR-0022 existed.
 */
final class AssetRelocationTest extends TestCase
{
    use RefreshDatabase;

    private StorageManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = $this->app->make(StorageManager::class);
        $this->manager->register('origin', new LocalStorageProvider(Storage::fake('origin'), 'origin'));
        $this->manager->register('target', new LocalStorageProvider(Storage::fake('target'), 'target'));
        $this->app->instance(StorageManager::class, $this->manager);
    }

    // ---------------------------------------------------------- the freeze

    #[Test]
    public function an_unsanctioned_disk_change_still_throws_exactly_as_before(): void
    {
        $asset = $this->storedAsset('a-master.wav', 'the bytes');

        $this->expectException(AssetIntegrityException::class);

        $asset->disk = 'target';
        $asset->save();
    }

    #[Test]
    public function the_sanction_blesses_one_save_and_only_a_pure_disk_change(): void
    {
        $asset = $this->storedAsset('a-master.wav', 'the bytes');

        // Sanctioned, but the save also rewrites identity: refused. That is
        // not a relocation, it is a rewrite wearing one.
        $asset->sanctionRelocation();
        $asset->disk = 'target';
        $asset->sha256 = hash('sha256', 'different bytes');

        try {
            $asset->save();
            $this->fail('A sanctioned save was allowed to rewrite identity.');
        } catch (AssetIntegrityException) {
            // And the sanction was consumed by the refusal — a bare disk
            // change afterwards must throw again, not inherit the blessing.
            $asset->refresh();
            $asset->disk = 'target';

            $this->expectException(AssetIntegrityException::class);
            $asset->save();
        }
    }

    // ------------------------------------------------------- the migration

    #[Test]
    public function a_verified_copy_switches_the_disk_and_never_touches_the_source(): void
    {
        $asset = $this->storedAsset('masters/one.wav', 'original master bytes');

        $report = $this->service()->toProvider('target', limit: 10);

        $this->assertSame([$asset->uuid], $report['moved']);
        $this->assertSame([], $report['failed']);

        $asset->refresh();
        $this->assertSame('target', $asset->disk);

        // Both copies exist: the source was not deleted, deliberately.
        $this->assertTrue($this->manager->provider('target')->exists('masters/one.wav'));
        $this->assertTrue($this->manager->provider('origin')->exists('masters/one.wav'));

        // Identity never moved.
        $this->assertSame(hash('sha256', 'original master bytes'), $asset->sha256);
    }

    #[Test]
    public function a_mismatched_copy_is_removed_and_the_asset_stays_where_it_was(): void
    {
        // The asset's record lies about its bytes (as a corrupted source
        // would): the target copy will hash to the real bytes, disagree with
        // the record, and must be removed — never blessed by re-hashing the
        // source into a matching pair of wrong checksums.
        $asset = $this->storedAsset('masters/two.wav', 'what is actually stored');
        Asset::withoutEvents(function () use ($asset): void {
            $asset->forceFill(['sha256' => hash('sha256', 'what the record claims')])->save();
        });

        $report = $this->service()->toProvider('target', limit: 10);

        $this->assertSame([], $report['moved']);
        $this->assertArrayHasKey($asset->uuid, $report['failed']);

        $asset->refresh();
        $this->assertSame('origin', $asset->disk);
        $this->assertFalse($this->manager->provider('target')->exists('masters/two.wav'), 'The unverified copy must not linger.');
        $this->assertTrue($this->manager->provider('origin')->exists('masters/two.wav'), 'The source must never be touched.');
    }

    #[Test]
    public function done_is_detected_not_remembered_so_a_second_run_skips(): void
    {
        $this->storedAsset('masters/three.wav', 'bytes');

        $first = $this->service()->toProvider('target', limit: 10);
        $second = $this->service()->toProvider('target', limit: 10);

        $this->assertCount(1, $first['moved']);
        $this->assertSame([], $second['moved']);
        $this->assertSame(1, $second['already']);
    }

    #[Test]
    public function one_failing_file_does_not_strand_the_others(): void
    {
        $good = $this->storedAsset('masters/good.wav', 'good bytes');
        $bad = $this->storedAsset('masters/bad.wav', 'bad bytes');
        Asset::withoutEvents(function () use ($bad): void {
            $bad->forceFill(['sha256' => hash('sha256', 'a lie')])->save();
        });

        $report = $this->service()->toProvider('target', limit: 10);

        $this->assertSame([$good->uuid], $report['moved']);
        $this->assertArrayHasKey($bad->uuid, $report['failed']);
    }

    #[Test]
    public function a_dry_run_names_the_work_and_moves_nothing(): void
    {
        $asset = $this->storedAsset('masters/four.wav', 'bytes');

        $report = $this->service()->toProvider('target', limit: 10, dryRun: true);

        $this->assertSame([$asset->uuid], $report['moved']);
        $this->assertFalse($this->manager->provider('target')->exists('masters/four.wav'));
        $this->assertSame('origin', $asset->refresh()->disk);
    }

    #[Test]
    public function an_unknown_target_stops_before_the_first_byte(): void
    {
        $this->storedAsset('masters/five.wav', 'bytes');

        $this->expectException(\Throwable::class);

        $this->service()->toProvider('nowhere', limit: 10);
    }

    #[Test]
    public function the_command_reports_and_keeps_the_source_sentence(): void
    {
        $this->storedAsset('masters/six.wav', 'bytes');

        $this->artisan('sanitube:assets:relocate', ['target' => 'target'])
            ->expectsOutputToContain('Sources were not deleted')
            ->assertSuccessful();
    }

    // ----------------------------------------------------------- fixtures

    private function service(): RelocateAssets
    {
        return new RelocateAssets($this->manager);
    }

    private function storedAsset(string $path, string $contents): Asset
    {
        $this->manager->provider('origin')->put($path, $contents);

        return Asset::factory()->create([
            'disk' => 'origin',
            'path' => $path,
            'sha256' => hash('sha256', $contents),
            'byte_size' => strlen($contents),
            'status' => 'STORED',
        ]);
    }
}
