<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Services\AssetStorageService;
use SaniTube\Assets\Services\StagingJanitor;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * The only scheduled task whose bug would read "deleted a master".
 *
 * Most of these are about what the sweep must *not* touch. That balance is
 * deliberate: an abandoned staging object costs disk, and a deleted master
 * costs the catalogue.
 */
final class StagingCleanupTest extends TestCase
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
    public function it_removes_an_upload_that_was_abandoned(): void
    {
        $this->store->put('staging/abandoned/original.wav', 'half an upload');
        $this->store->backdate('staging/abandoned/original.wav', Carbon::now()->subDays(3)->getTimestamp());

        $report = $this->janitor()->sweep();

        $this->assertSame(['staging/abandoned/original.wav'], $report->removed);
        $this->assertFalse($this->store->exists('staging/abandoned/original.wav'));
    }

    #[Test]
    public function it_leaves_an_upload_that_is_still_in_progress(): void
    {
        // A slow upload of a large master on a poor connection is not an
        // abandoned one.
        $this->store->put('staging/in-progress/original.wav', 'still going');

        $report = $this->janitor()->sweep();

        $this->assertSame([], $report->removed);
        $this->assertSame(1, $report->kept);
        $this->assertTrue($this->store->exists('staging/in-progress/original.wav'));
    }

    #[Test]
    public function it_never_touches_an_asset_of_record(): void
    {
        $stored = $this->storedAsset();

        // Old enough to be swept, were it in scope at all.
        $this->store->backdate($stored->path, Carbon::now()->subYear()->getTimestamp());

        $report = $this->janitor()->sweep();

        $this->assertSame([], $report->removed);
        $this->assertTrue($this->store->exists($stored->path), 'The sweep deleted a stored asset.');
    }

    #[Test]
    public function it_never_touches_anything_outside_the_staging_prefix(): void
    {
        foreach (['masters/x/original.wav', 'artwork/y/original.jpg', 'exports/z/original.xml'] as $key) {
            $this->store->put($key, 'bytes');
            $this->store->backdate($key, Carbon::now()->subYear()->getTimestamp());
        }

        $this->assertSame([], $this->janitor()->sweep()->removed);
        $this->assertCount(3, $this->store->keys());
    }

    #[Test]
    public function an_asset_that_somehow_claims_a_staging_key_is_spared(): void
    {
        // Should be impossible — canonical keys never live under the staging
        // prefix. Checked anyway, because the cost of being wrong here is a
        // lost master and the cost of the check is one query.
        $asset = Asset::factory()->create([
            'disk' => 'memory',
            'path' => 'staging/claimed/original.wav',
            'status' => AssetStatus::Stored,
            'stored_at' => Carbon::now(),
        ]);

        $this->store->put($asset->path, 'bytes');
        $this->store->backdate($asset->path, Carbon::now()->subYear()->getTimestamp());

        $report = $this->janitor()->sweep();

        $this->assertSame([], $report->removed);
        $this->assertTrue($this->store->exists($asset->path));
    }

    #[Test]
    public function a_dry_run_reports_without_deleting(): void
    {
        $this->store->put('staging/abandoned/original.wav', 'half an upload');
        $this->store->backdate('staging/abandoned/original.wav', Carbon::now()->subDays(3)->getTimestamp());

        $report = $this->janitor()->sweep(dryRun: true);

        $this->assertSame(['staging/abandoned/original.wav'], $report->removed);
        $this->assertTrue($report->dryRun);
        $this->assertTrue($this->store->exists('staging/abandoned/original.wav'));
    }

    #[Test]
    public function the_age_threshold_can_be_narrowed(): void
    {
        $this->store->put('staging/recent/original.wav', 'bytes');
        $this->store->backdate('staging/recent/original.wav', Carbon::now()->subMinutes(30)->getTimestamp());

        $this->assertSame([], $this->janitor()->sweep()->removed);
        $this->assertSame(['staging/recent/original.wav'], $this->janitor()->sweep(olderThanSeconds: 60)->removed);
    }

    #[Test]
    public function the_report_names_what_it_removed_rather_than_counting_it(): void
    {
        // A cleanup that reports "12 objects removed" cannot be audited
        // against a bucket listing afterwards.
        $this->store->put('staging/a/original.wav', 'a');
        $this->store->backdate('staging/a/original.wav', Carbon::now()->subDays(2)->getTimestamp());

        $report = $this->janitor()->sweep();

        $this->assertSame('memory', $report->provider);
        $this->assertSame(1, $report->count());
        $this->assertSame(['staging/a/original.wav'], $report->toArray()['removed']);
    }

    private function storedAsset(): Asset
    {
        $service = $this->app->make(AssetStorageService::class);
        $asset = $service->register(AssetKind::AudioMaster, 'master.wav');

        $stream = fopen('php://memory', 'r+');

        if ($stream === false) {
            $this->fail('Could not open a memory stream.');
        }

        fwrite($stream, 'a real master');
        rewind($stream);

        return $service->store($asset, $stream);
    }

    private function janitor(): StagingJanitor
    {
        return $this->app->make(StagingJanitor::class);
    }
}
