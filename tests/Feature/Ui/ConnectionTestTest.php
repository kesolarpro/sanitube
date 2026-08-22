<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Observability\Certification\CertificationStatus;
use SaniTube\Observability\Certification\ProviderStandings;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use SaniTube\Ui\Queries\SettingsQuery;
use SaniTube\Ui\Settings\ConnectionProbe;
use Tests\TestCase;

/**
 * "Does this actually work?", asked from the dashboard.
 *
 * CFG-001. The probes themselves already existed as commands; what these
 * tests hold is that the button runs the *same* probe, records a *real*
 * certification when it passes, and never lets a credential or an address
 * into the response — a settings screen is the most screenshotted page in
 * any application.
 */
final class ConnectionTestTest extends TestCase
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

    // ------------------------------------------------------------ storage

    #[Test]
    public function the_probe_really_writes_reads_and_deletes_and_leaves_nothing_behind(): void
    {
        $before = $this->store->files('');

        $response = $this->actingAs($this->owner())->postJson('/settings/test', ['target' => 'storage']);

        $response->assertOk();

        $outcomes = [];

        foreach ($response->json('checks') as $check) {
            $outcomes[$check['name']] = $check['outcome'];
        }

        // The round trip the mandate asks for, each one real against the
        // configured store.
        foreach (['write', 'read', 'checksum', 'move', 'delete'] as $step) {
            $this->assertSame('passed', $outcomes[$step] ?? null, sprintf('[%s] did not pass.', $step));
        }

        // The probe writes and deletes its own object. A certification that
        // left litter would be a certification that costs storage every time
        // somebody presses it.
        $this->assertSame($before, $this->store->files(''));
    }

    #[Test]
    public function a_signed_url_never_reaches_the_browser_even_inside_a_failure(): void
    {
        // A failed signed read quotes the URL it could not fetch — signature
        // and all. That is acceptable in a terminal an operator already has
        // and unacceptable in a response somebody screenshots.
        $response = $this->actingAs($this->owner())->postJson('/settings/test', ['target' => 'storage']);

        $body = (string) $response->getContent();

        $this->assertStringNotContainsString('signature=', $body);
        $this->assertStringNotContainsString('http://', $body);
        $this->assertStringNotContainsString('https://', $body);
    }

    #[Test]
    public function a_store_that_will_not_answer_is_failed_not_a_crash(): void
    {
        $this->store->failWith('The store is not answering.');

        $response = $this->actingAs($this->owner())->postJson('/settings/test', ['target' => 'storage']);

        $response->assertOk();
        $this->assertContains($response->json('status'), ['FAILED', 'DEGRADED']);
    }

    #[Test]
    public function a_pass_records_a_real_certification_not_a_green_light(): void
    {
        // The whole point of the ledger: sanitube:providers must not be able
        // to report CERTIFIED from a button that merely lit up.
        $standings = $this->app->make(ProviderStandings::class);

        $this->actingAs($this->owner())->postJson('/settings/test', ['target' => 'storage'])->assertOk();

        $status = null;

        foreach ($standings->assess() as $standing) {
            if ($standing->key === 'object_storage') {
                $status = $standing->status;
            }
        }

        // The in-memory store is not object storage, so this installation's
        // storage standing stays NOT_CONFIGURED. What this holds is the
        // inverse and the one that matters: a button press must never be able
        // to *invent* a CERTIFIED standing.
        $this->assertNotNull($status);
        $this->assertNotSame(CertificationStatus::Certified, $status);
    }

    // ------------------------------------------------------------- worker

    #[Test]
    public function an_unconfigured_worker_says_so_rather_than_failing(): void
    {
        config(['worker.url' => '', 'worker.token' => '']);

        $response = $this->actingAs($this->owner())->postJson('/settings/test', ['target' => 'worker']);

        $response->assertOk();
        $this->assertSame('NOT_CONFIGURED', $response->json('status'));
    }

    #[Test]
    public function a_worker_that_does_not_answer_is_failed_without_naming_its_address(): void
    {
        config(['worker.url' => 'http://worker.internal:9000', 'worker.token' => str_repeat('t', 40)]);

        Http::fake(static function (): void {
            throw new ConnectionException('cURL error 7: Failed to connect to worker.internal port 9000');
        });

        $response = $this->actingAs($this->owner())->postJson('/settings/test', ['target' => 'worker']);

        $response->assertOk();
        $this->assertSame('FAILED', $response->json('status'));

        // The address is the one thing a screenshot must not carry.
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('worker.internal', $body);
        $this->assertStringNotContainsString('9000', $body);
    }

    #[Test]
    public function the_worker_token_never_reaches_the_response(): void
    {
        $token = 'a-very-secret-worker-token-value-here';

        config(['worker.url' => 'http://worker.internal:9000', 'worker.token' => $token]);

        Http::fake(['*' => Http::response(['name' => 'w', 'capabilities' => []], 200)]);

        $response = $this->actingAs($this->owner())->postJson('/settings/test', ['target' => 'worker']);

        $this->assertStringNotContainsString($token, (string) $response->getContent());
    }

    // -------------------------------------------------------- the doorway

    #[Test]
    public function every_probe_the_screen_offers_is_one_the_endpoint_runs(): void
    {
        // Derived from the payload, never from a list kept here. A section
        // that offered a probe this endpoint rejects would put a button on the
        // screen that answers 422, and nobody would find out from a test that
        // asserts the two words somebody remembered to type.
        $offered = [];

        /** @var list<array<string, mixed>> $sections */
        $sections = $this->app->make(SettingsQuery::class)->overview()['sections'];

        foreach ($sections as $section) {
            if ($section['probe'] !== null) {
                $offered[] = $section['probe'];
            }
        }

        // The screen must offer some. A payload that quietly stopped naming
        // any would take every test button off the page without failing
        // anything else.
        $this->assertNotEmpty($offered);

        foreach ($offered as $target) {
            $this->actingAs($this->owner())
                ->postJson('/settings/test', ['target' => $target])
                ->assertOk();
        }

        // And the reverse: an endpoint that accepted a word no section can
        // produce is a doorway with no door in front of it.
        sort($offered);
        $words = ConnectionProbe::words();
        sort($words);

        $this->assertSame($words, $offered);
    }

    #[Test]
    public function the_target_is_a_closed_vocabulary(): void
    {
        // A target that named a URL would make this button a request the
        // caller composes, which is how a settings screen becomes an SSRF
        // tool.
        $this->actingAs($this->owner())
            ->postJson('/settings/test', ['target' => 'http://169.254.169.254/latest/meta-data/'])
            ->assertStatus(422);

        $this->actingAs($this->owner())
            ->postJson('/settings/test', ['target' => 'database'])
            ->assertStatus(422);
    }

    #[Test]
    public function somebody_who_may_not_change_settings_may_not_test_them(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Member, 'is_active' => true]);

        $this->actingAs($viewer)
            ->postJson('/settings/test', ['target' => 'storage'])
            ->assertForbidden();
    }

    #[Test]
    public function a_stranger_gets_nothing(): void
    {
        $this->postJson('/settings/test', ['target' => 'storage'])->assertUnauthorized();
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => UserRole::Owner, 'is_active' => true]);
    }
}
