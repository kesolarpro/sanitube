<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Services\AssetStorageService;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * The three commands an operator actually runs, and what they print.
 *
 * What they print matters as much as what they do. These are the outputs that
 * end up in cron mail and support threads, so a secret in one of them is a
 * leaked secret, and a truncated pass that looks complete is a lie an operator
 * will act on.
 */
final class StorageCommandsTest extends TestCase
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

    // ------------------------------------------------ sanitube:storage:check

    #[Test]
    public function the_storage_check_passes_against_a_working_provider(): void
    {
        $this->artisan('sanitube:storage:check', ['provider' => ['memory']])
            ->assertSuccessful();
    }

    #[Test]
    public function the_storage_check_fails_against_a_provider_that_is_down(): void
    {
        // Credentials that parse are not credentials that work; this is the
        // command that tells them apart.
        $this->store->failWith('The endpoint refused the connection.');

        $this->artisan('sanitube:storage:check', ['provider' => ['memory']])
            ->assertFailed();
    }

    #[Test]
    public function the_storage_check_reports_an_unresolvable_provider_rather_than_crashing(): void
    {
        $this->artisan('sanitube:storage:check', ['provider' => ['nowhere']])
            ->assertFailed();
    }

    #[Test]
    public function the_storage_check_never_prints_a_credential(): void
    {
        config(['filesystems.disks.somewhere.secret' => 'sup3rs3cret-value-that-must-never-print']);

        $this->store->failWith('SignatureDoesNotMatch using sup3rs3cret-value-that-must-never-print');

        $this->artisan('sanitube:storage:check', ['provider' => ['memory'], '--json' => true])
            ->doesntExpectOutputToContain('sup3rs3cret-value-that-must-never-print')
            ->assertFailed();
    }

    #[Test]
    public function the_storage_check_warns_when_a_provider_cannot_sign_urls(): void
    {
        $unsigned = new InMemoryStorageProvider('unsigned', temporaryUrls: false);
        $this->app->make(StorageManager::class)->register('unsigned', $unsigned);

        $this->artisan('sanitube:storage:check', ['provider' => ['unsigned']])
            ->expectsOutputToContain('cannot sign URLs')
            ->assertSuccessful();
    }

    // ----------------------------------------------- sanitube:assets:verify

    #[Test]
    public function verifying_intact_assets_succeeds(): void
    {
        $this->storedAsset('a real master');

        $this->artisan('sanitube:assets:verify')->assertSuccessful();
    }

    #[Test]
    public function verifying_reports_failure_when_an_object_has_gone(): void
    {
        $asset = $this->storedAsset('a real master');
        $this->store->delete($asset->path);

        $this->artisan('sanitube:assets:verify')->assertFailed();

        $this->assertSame(AssetStatus::Missing, $asset->refresh()->status);
    }

    #[Test]
    public function verifying_refuses_a_status_it_cannot_conclude_anything_about(): void
    {
        $this->artisan('sanitube:assets:verify', ['--status' => 'PENDING'])
            ->expectsOutputToContain('applies to STORED and VERIFIED')
            ->assertExitCode(2);
    }

    #[Test]
    public function verifying_says_what_it_did_not_check(): void
    {
        // A truncated pass that looks like a complete one is worse than no
        // pass at all.
        $this->storedAsset('one');
        $this->storedAsset('two');

        $this->artisan('sanitube:assets:verify', ['--limit' => '1'])
            ->expectsOutputToContain('were not checked')
            ->assertSuccessful();
    }

    #[Test]
    public function verifying_can_be_restricted_to_one_kind(): void
    {
        $master = $this->storedAsset('a real master');

        $this->artisan('sanitube:assets:verify', ['--kind' => 'ARTWORK'])->assertSuccessful();

        // The master was out of scope, so it is untouched rather than verified.
        $this->assertSame(AssetStatus::Stored, $master->refresh()->status);
    }

    // --------------------------------------- sanitube:assets:cleanup-staging

    #[Test]
    public function the_cleanup_removes_abandoned_uploads_and_names_them(): void
    {
        $key = $this->abandonedUpload();

        $this->artisan('sanitube:assets:cleanup-staging')
            ->expectsOutputToContain($key)
            ->assertSuccessful();

        $this->assertFalse($this->store->exists($key));
    }

    #[Test]
    public function the_cleanup_can_be_asked_what_it_would_do(): void
    {
        // What the deployment docs tell an operator to run before enabling the
        // cron for the first time.
        $key = $this->abandonedUpload();

        $this->artisan('sanitube:assets:cleanup-staging', ['--dry-run' => true])
            ->expectsOutputToContain('dry run')
            ->assertSuccessful();

        $this->assertTrue($this->store->exists($key));
    }

    #[Test]
    public function the_cleanup_warns_before_sweeping_below_the_age_floor(): void
    {
        // Allowed, because a person typed it. Never silent, because it can
        // delete an upload that is still in flight.
        $this->store->put($key = 'staging/'.Str::uuid7().'/original.wav', 'in flight');
        $this->store->backdate($key, Carbon::now()->subMinutes(2)->getTimestamp());

        $this->artisan('sanitube:assets:cleanup-staging', ['--hours' => '0'])
            ->expectsOutputToContain('below the')
            ->assertSuccessful();

        $this->assertFalse($this->store->exists($key));
    }

    #[Test]
    public function the_scheduled_cleanup_cannot_reach_the_age_override(): void
    {
        // The structural half of the floor: the scheduler passes no options,
        // so there is no configuration of the environment that lets a cron run
        // sweep below an hour.
        $commands = array_map(
            static fn (Event $event): string => $event->command ?? '',
            $this->app->make(Schedule::class)->events(),
        );

        $cleanup = array_values(array_filter(
            $commands,
            static fn (string $command): bool => str_contains($command, 'assets:cleanup-staging'),
        ));

        $this->assertCount(1, $cleanup, 'The staging cleanup is not scheduled.');
        $this->assertStringNotContainsString('--hours', $cleanup[0]);
    }

    #[Test]
    public function the_cleanup_leaves_a_stored_asset_alone(): void
    {
        $asset = $this->storedAsset('a real master');
        $this->store->backdate($asset->path, Carbon::now()->subYear()->getTimestamp());

        $this->artisan('sanitube:assets:cleanup-staging')->assertSuccessful();

        $this->assertTrue($this->store->exists($asset->path));
    }

    #[Test]
    public function all_three_commands_are_registered(): void
    {
        $registered = array_keys($this->app->make(Kernel::class)->all());

        $this->assertContains('sanitube:storage:check', $registered);
        $this->assertContains('sanitube:assets:verify', $registered);
        $this->assertContains('sanitube:assets:cleanup-staging', $registered);
    }

    /**
     * A staging object of the exact shape the platform writes, old enough to
     * sweep.
     */
    private function abandonedUpload(): string
    {
        $key = 'staging/'.Str::uuid7().'/original.wav';

        $this->store->put($key, 'half an upload');
        $this->store->backdate($key, Carbon::now()->subDays(3)->getTimestamp());

        return $key;
    }

    private function storedAsset(string $contents): Asset
    {
        $service = $this->app->make(AssetStorageService::class);
        $asset = $service->register(AssetKind::AudioMaster, 'master.wav');

        $stream = fopen('php://memory', 'r+');

        if ($stream === false) {
            $this->fail('Could not open a memory stream.');
        }

        fwrite($stream, $contents);
        rewind($stream);

        return $service->store($asset, $stream);
    }
}
