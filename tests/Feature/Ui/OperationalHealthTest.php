<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Observability\Health\OperationalHealth;
use SaniTube\Observability\Health\OperationalHealthProbe;
use SaniTube\Observability\Health\OperationalHealthStore;
use SaniTube\Storage\Contracts\StorageProvider;
use SaniTube\Storage\StorageHealth;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\StoredObject;
use SaniTube\Ui\Queries\DashboardQuery;
use Tests\TestCase;

/**
 * The boundary between "what a page may read" and "what only a scheduled job
 * may do".
 *
 * Probing storage writes and deletes an object. Probing a provider crosses the
 * network. Probing capabilities shells out. A page request that does all three
 * is slow when everything is healthy and unresponsive when it is not — which is
 * exactly the moment somebody opens the dashboard to find out what is wrong.
 *
 * These tests exist because that boundary is invisible: nothing about a passing
 * dashboard test tells you whether it made five network calls to get there.
 */
final class OperationalHealthTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function loading_the_dashboard_never_probes_storage(): void
    {
        // The assertion this whole ticket exists for. The provider is rigged to
        // fail loudly if anything asks it for health, or writes, or deletes.
        $provider = $this->tripwireStorage();

        $this->actingAs($this->user())->get('/')->assertOk();

        $this->assertSame([], $provider->forbiddenCalls, implode(', ', $provider->forbiddenCalls));
    }

    #[Test]
    public function loading_the_dashboard_does_not_touch_the_object_store_at_all(): void
    {
        $provider = $this->tripwireStorage();

        $this->actingAs($this->user())->get('/')->assertOk();

        // Not just writes: a listing over a bucket with a hundred thousand
        // objects is its own kind of page-load disaster.
        $this->assertSame([], $provider->allCalls, implode(', ', $provider->allCalls));
    }

    #[Test]
    public function the_dashboard_renders_when_every_provider_is_unreachable(): void
    {
        $this->tripwireStorage();
        app(OperationalHealthStore::class)->forget();

        $this->actingAs($this->user())->get('/')->assertOk();
    }

    // ------------------------------------------------------------- the probe

    #[Test]
    public function the_refresh_command_records_a_snapshot(): void
    {
        $store = app(OperationalHealthStore::class);
        $store->forget();

        $this->assertNull($store->current());

        $this->artisan('sanitube:health:refresh')->assertSuccessful();

        $snapshot = $store->current();

        $this->assertInstanceOf(OperationalHealth::class, $snapshot);
        $this->assertArrayHasKey('healthy', $snapshot->storage);
        $this->assertArrayHasKey('items', $snapshot->capabilities);
    }

    #[Test]
    public function a_provider_that_throws_while_being_probed_is_recorded_as_unknown(): void
    {
        // Not as unhealthy. "The provider said no" and "we could not reach the
        // provider to ask" send an operator to different places.
        $provider = $this->tripwireStorage();

        $health = app(OperationalHealthProbe::class)->run();

        // The probe *did* ask — that is its job — and the provider threw.
        $this->assertContains('healthCheck', $provider->forbiddenCalls);
        $this->assertNull($health->storage['healthy'], 'A provider that threw was reported as a verdict.');
    }

    // ------------------------------------------------------- what the UI sees

    #[Test]
    public function a_missing_snapshot_reads_as_unknown_rather_than_healthy(): void
    {
        app(OperationalHealthStore::class)->forget();

        $operational = app(DashboardQuery::class)->snapshot()['operational'];

        $this->assertSame('UNKNOWN', $operational['state']);
        $this->assertNull($operational['checked_at']);
        $this->assertNull($operational['storage']['healthy']);
        $this->assertNull($operational['ai']['available']);
        $this->assertSame([], $operational['distributors']);
        $this->assertNull($operational['capabilities']['healthy']);
    }

    #[Test]
    public function a_stale_snapshot_never_reports_healthy(): void
    {
        // The failure mode that matters most: a snapshot taken before an outage
        // is not evidence that the outage is not happening. Letting a stale
        // `healthy` through is how a dashboard shows green through a failure.
        $store = app(OperationalHealthStore::class);
        $tooOld = (new DateTimeImmutable)->modify('-'.($store->staleAfterSeconds() + 60).' seconds');

        $store->put(new OperationalHealth(
            checkedAt: $tooOld,
            storage: ['provider' => 's3', 'healthy' => true, 'checks' => ['write' => true], 'temporary_urls' => true, 'detail' => null],
            ai: ['name' => 'openai', 'available' => true],
            generation: ['name' => 'suno', 'available' => true],
            distributors: [['name' => 'toolost', 'available' => true]],
            capabilities: ['healthy' => true, 'items' => []],
        ));

        $operational = app(DashboardQuery::class)->snapshot()['operational'];

        $this->assertSame('STALE', $operational['state']);
        $this->assertNull($operational['storage']['healthy'], 'A stale snapshot reported storage as healthy.');
        $this->assertNull($operational['ai']['available']);
        $this->assertNull($operational['generation_provider']['available']);
        $this->assertNull($operational['distributors'][0]['available']);
        $this->assertNull($operational['capabilities']['healthy']);

        // What was checked is still worth showing; only the verdicts are gone.
        $this->assertSame('s3', $operational['storage']['provider']);
        $this->assertSame('toolost', $operational['distributors'][0]['name']);
        $this->assertNotNull($operational['checked_at']);
    }

    #[Test]
    public function a_fresh_snapshot_passes_its_verdicts_through(): void
    {
        $store = app(OperationalHealthStore::class);

        $store->put(new OperationalHealth(
            checkedAt: new DateTimeImmutable,
            storage: ['provider' => 's3', 'healthy' => true, 'checks' => [], 'temporary_urls' => true, 'detail' => null],
            ai: ['name' => 'openai', 'available' => false],
            generation: ['name' => 'none', 'available' => false],
            distributors: [],
            capabilities: ['healthy' => true, 'items' => []],
        ));

        $operational = app(DashboardQuery::class)->snapshot()['operational'];

        $this->assertSame('FRESH', $operational['state']);
        $this->assertTrue($operational['storage']['healthy']);
        $this->assertFalse($operational['ai']['available']);
    }

    #[Test]
    public function the_checked_at_timestamp_is_exposed_and_carries_nothing_else(): void
    {
        $secret = 'a-real-looking-storage-secret-value';
        $token = 'a-real-looking-health-token-value';

        config([
            'storage.providers.s3.secret' => $secret,
            'sanitube.health.token' => $token,
        ]);

        $this->artisan('sanitube:health:refresh')->assertSuccessful();

        $operational = app(DashboardQuery::class)->snapshot()['operational'];

        $this->assertIsString($operational['checked_at']);
        $this->assertNotFalse(strtotime($operational['checked_at']));
        $this->assertIsInt($operational['stale_after_seconds']);

        // No credential rode along with it.
        //
        // Asserted against configured *values*, not against English words: a
        // capability's remediation legitimately reads "password resets and job
        // failure notices depend on it", and a check that trips on that is a
        // check people learn to delete. Same false positive as the bare `key`
        // and `path` matches caught earlier in this project.
        $encoded = json_encode($operational);
        $this->assertIsString($encoded);

        foreach ([$secret, $token] as $credential) {
            $this->assertStringNotContainsString($credential, $encoded);
        }
    }

    #[Test]
    public function a_corrupt_stored_snapshot_reads_as_absent(): void
    {
        // A half-parsed health report is worse than none: the missing halves
        // render as unknown while the surviving halves look authoritative.
        cache()->put((string) config('sanitube.health.operational.cache_key'), ['checked_at' => 'not a date'], 600);

        $this->assertNull(app(OperationalHealthStore::class)->current());
        $this->assertSame('UNKNOWN', app(DashboardQuery::class)->snapshot()['operational']['state']);
    }

    // --------------------------------------------------------------- helpers

    private function tripwireStorage(): TripwireStorageProvider
    {
        $provider = new TripwireStorageProvider;
        $manager = app(StorageManager::class);

        // Registered under the *current* default name: the manager is a
        // singleton that already read the config, so writing config here would
        // change nothing it looks at again.
        $manager->register($manager->defaultName(), $provider);

        return $provider;
    }

    private function user(): User
    {
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'a-long-enough-passphrase',
        ]);

        return $user->forceFill(['role' => UserRole::Owner, 'is_active' => true]);
    }
}

/**
 * A storage provider that refuses to be touched, and remembers who tried.
 *
 * Every method records the attempt and then throws. Recording alone would be
 * enough for the assertion, but throwing makes an accidental call impossible to
 * miss: it surfaces as a failed page rather than as a quiet entry in a list
 * somebody has to remember to check.
 *
 * Written out rather than wrapping InMemoryStorageProvider, which is final. The
 * cost is a long file; the benefit is that adding a method to the contract
 * breaks this class loudly instead of leaving a tripwire silently unwired.
 */
final class TripwireStorageProvider implements StorageProvider
{
    /** @var list<string> */
    public array $allCalls = [];

    /** @var list<string> */
    public array $forbiddenCalls = [];

    public function name(): string
    {
        // Not recorded: naming the provider is metadata, not access.
        return 'tripwire';
    }

    public function supportsTemporaryUrls(): bool
    {
        return false;
    }

    public function healthCheck(): StorageHealth
    {
        $this->refuse('healthCheck', forbidden: true);
    }

    public function put(string $key, mixed $contents, array $options = []): StoredObject
    {
        $this->refuse('put', forbidden: true);
    }

    public function putStream(string $key, $stream, array $options = []): StoredObject
    {
        $this->refuse('putStream', forbidden: true);
    }

    public function putFile(string $key, string $localPath, array $options = []): StoredObject
    {
        $this->refuse('putFile', forbidden: true);
    }

    public function delete(string $key): bool
    {
        $this->refuse('delete', forbidden: true);
    }

    public function move(string $from, string $to): StoredObject
    {
        $this->refuse('move', forbidden: true);
    }

    public function get(string $key): string
    {
        $this->refuse('get');
    }

    public function readStream(string $key)
    {
        $this->refuse('readStream');
    }

    public function exists(string $key): bool
    {
        $this->refuse('exists');
    }

    public function size(string $key): int
    {
        $this->refuse('size');
    }

    public function lastModified(string $key): int
    {
        $this->refuse('lastModified');
    }

    public function files(string $prefix = ''): array
    {
        $this->refuse('files');
    }

    public function checksum(string $key, string $algorithm = 'sha256'): string
    {
        $this->refuse('checksum');
    }

    public function url(string $key): string
    {
        $this->refuse('url');
    }

    public function temporaryUrl(string $key, DateTimeInterface $expiresAt): string
    {
        $this->refuse('temporaryUrl');
    }

    /**
     * @return never
     */
    private function refuse(string $call, bool $forbidden = false)
    {
        $this->allCalls[] = $call;

        if ($forbidden) {
            $this->forbiddenCalls[] = $call;
        }

        throw new RuntimeException(sprintf('A page request called StorageProvider::%s().', $call));
    }
}
