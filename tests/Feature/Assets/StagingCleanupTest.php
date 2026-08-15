<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
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
 *
 * Deletion requires five conditions at once — exact prefix scope, a key shape
 * this platform could have written, no asset claiming it, age past the
 * threshold, and not a dry run. There is a test per condition, each arranged so
 * that only the one under test fails.
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

    // ------------------------------------------------------- the happy path

    #[Test]
    public function it_removes_an_upload_that_was_abandoned(): void
    {
        $key = $this->abandoned();

        $report = $this->janitor()->sweep();

        $this->assertSame([$key], $report->removed);
        $this->assertFalse($this->store->exists($key));
    }

    // ------------------------------------------------- D: the age threshold

    #[Test]
    public function it_leaves_an_upload_that_is_still_in_progress(): void
    {
        // A slow upload of a large master on a poor connection is not an
        // abandoned one.
        $key = $this->stagingKey();
        $this->store->put($key, 'still going');

        $report = $this->janitor()->sweep();

        $this->assertSame([], $report->removed);
        $this->assertSame(1, $report->kept());
        $this->assertSame('newer than the age threshold', $report->skipped[$key]);
    }

    #[Test]
    public function the_configured_age_can_never_fall_below_the_floor(): void
    {
        // The typo this exists for: SANITUBE_STAGING_TTL_HOURS=0 means "delete
        // every upload currently in flight". It is clamped rather than obeyed.
        config(['assets.staging.ttl_hours' => 0]);

        $key = $this->stagingKey();
        $this->store->put($key, 'in flight');
        $this->store->backdate($key, Carbon::now()->subMinutes(5)->getTimestamp());

        $report = $this->janitor()->sweep();

        $this->assertSame([], $report->removed);
        $this->assertSame(StagingJanitor::MINIMUM_AGE_SECONDS, $report->ageSeconds);
        $this->assertTrue($this->store->exists($key));
    }

    #[Test]
    public function an_explicitly_narrowed_age_is_still_clamped_unless_the_caller_says_otherwise(): void
    {
        $key = $this->stagingKey();
        $this->store->put($key, 'bytes');
        $this->store->backdate($key, Carbon::now()->subMinutes(30)->getTimestamp());

        $clamped = $this->janitor()->sweep(olderThanSeconds: 60);

        $this->assertSame([], $clamped->removed);
        $this->assertSame(StagingJanitor::MINIMUM_AGE_SECONDS, $clamped->ageSeconds);
    }

    #[Test]
    public function a_deliberate_override_may_go_below_the_floor(): void
    {
        // The escape hatch a person at a terminal can reach, and the scheduler
        // cannot.
        $key = $this->stagingKey();
        $this->store->put($key, 'bytes');
        $this->store->backdate($key, Carbon::now()->subMinutes(30)->getTimestamp());

        $report = $this->janitor()->sweep(olderThanSeconds: 60, allowAgeBelowFloor: true);

        $this->assertSame([$key], $report->removed);
        $this->assertSame(60, $report->ageSeconds);
    }

    // ------------------------------------------------------ B: the key shape

    #[Test]
    #[DataProvider('keysNoPlatformWrote')]
    public function it_refuses_to_delete_a_key_it_could_not_have_written(string $key): void
    {
        // Starting with `staging/` is not enough. Anything that is not exactly
        // staging/{uuid}/original[.ext] was put there by something else, and
        // something else's object is not this task's to delete.
        $this->store->put($key, 'bytes');
        $this->store->backdate($key, Carbon::now()->subYear()->getTimestamp());

        $report = $this->janitor()->sweep();

        $this->assertSame([], $report->removed, sprintf('[%s] was deleted.', $key));
        $this->assertTrue($this->store->exists($key));
        $this->assertSame('not a staging object key this platform writes', $report->skipped[$key]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function keysNoPlatformWrote(): iterable
    {
        $uuid = '0192f2b3-4c5d-7e6f-8a9b-0c1d2e3f4a5b';

        yield 'a name that is not a uuid' => ['staging/foo/bar'];
        yield 'a plausible but non-uuid segment' => ['staging/abandoned/original.wav'];
        yield 'an extra path segment' => ['staging/'.$uuid.'/nested/original.wav'];
        yield 'a different object name' => ['staging/'.$uuid.'/secrets.env'];
        yield 'an empty extension' => ['staging/'.$uuid.'/original.'];
        yield 'an uppercase uuid' => ['staging/'.strtoupper($uuid).'/original.wav'];
        yield 'a truncated uuid' => ['staging/0192f2b3-4c5d/original.wav'];
        yield 'traversal in the uuid slot' => ['staging/../masters/x/original.wav'];
        yield 'encoded traversal' => ['staging/%2e%2e%2fmasters/original.wav'];
        yield 'the prefix alone' => ['staging/original.wav'];
    }

    // -------------------------------------------------------- A: the scope

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
    public function a_key_that_merely_starts_with_the_prefix_string_is_out_of_scope(): void
    {
        // `staging-archive/` shares a prefix with `staging/` as a string and is
        // a different place. The provider's scoping is what catches this, and
        // it is checked here because the janitor is the caller that would pay.
        $this->store->put('staging-archive/'.Str::uuid7().'/original.wav', 'bytes');
        $this->store->backdate((string) $this->store->keys()[0], Carbon::now()->subYear()->getTimestamp());

        $this->assertSame([], $this->janitor()->sweep()->removed);
        $this->assertCount(1, $this->store->keys());
    }

    // ------------------------------------------------------- C: the claim

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
    public function an_asset_that_somehow_claims_a_staging_key_is_spared(): void
    {
        // Should be impossible — canonical keys never live under the staging
        // prefix. Checked anyway, because the cost of being wrong here is a
        // lost master and the cost of the check is one query. The key is a
        // *valid* staging key, so only condition C stands between it and
        // deletion.
        $path = $this->stagingKey();

        $asset = Asset::factory()->create([
            'disk' => 'memory',
            'path' => $path,
            'status' => AssetStatus::Stored,
            'stored_at' => Carbon::now(),
        ]);

        $this->store->put($path, 'bytes');
        $this->store->backdate($path, Carbon::now()->subYear()->getTimestamp());

        $report = $this->janitor()->sweep();

        $this->assertSame([], $report->removed);
        $this->assertSame('claimed by an asset', $report->skipped[$path]);
        $this->assertTrue($this->store->exists($asset->path));
    }

    // ------------------------------------------------------ E: the dry run

    #[Test]
    public function a_dry_run_reports_without_deleting(): void
    {
        $key = $this->abandoned();

        $report = $this->janitor()->sweep(dryRun: true);

        $this->assertSame([$key], $report->removed);
        $this->assertTrue($report->dryRun);
        $this->assertTrue($this->store->exists($key));
    }

    // ---------------------------------------------------------- the report

    #[Test]
    public function the_report_names_what_it_removed_rather_than_counting_it(): void
    {
        // A cleanup that reports "12 objects removed" cannot be audited
        // against a storage listing afterwards.
        $key = $this->abandoned();

        $report = $this->janitor()->sweep();

        $this->assertSame('memory', $report->provider);
        $this->assertSame(1, $report->count());
        $this->assertSame([$key], $report->toArray()['removed']);
        $this->assertSame(86_400, $report->toArray()['age_seconds']);
    }

    #[Test]
    public function the_report_says_why_each_survivor_survived(): void
    {
        // An object that outlives every sweep forever is either a bug or an
        // intrusion, and a bare count says which for neither.
        $this->store->put('staging/foo/bar', 'bytes');
        $this->store->backdate('staging/foo/bar', Carbon::now()->subYear()->getTimestamp());

        $report = $this->janitor()->sweep();

        $this->assertSame(
            ['staging/foo/bar' => 'not a staging object key this platform writes'],
            $report->toArray()['skipped'],
        );
    }

    // ----------------------------------------------------------- helpers

    /**
     * A key of exactly the shape the platform writes.
     */
    private function stagingKey(): string
    {
        return 'staging/'.Str::uuid7().'/original.wav';
    }

    /**
     * A staging object old enough to sweep.
     */
    private function abandoned(): string
    {
        $key = $this->stagingKey();

        $this->store->put($key, 'half an upload');
        $this->store->backdate($key, Carbon::now()->subDays(3)->getTimestamp());

        return $key;
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
