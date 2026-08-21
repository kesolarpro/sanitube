<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Observability\Certification\CertificationLedger;
use SaniTube\Observability\Certification\CertificationStatus;
use SaniTube\Observability\Certification\ProviderStanding;
use SaniTube\Observability\Certification\ProviderStandings;
use Tests\TestCase;

/**
 * No vague booleans: every provider is in exactly one of six states, and
 * CERTIFIED can only be earned.
 *
 * DEP-013. The assessor derives from configuration and the certification
 * ledger; the ledger is written only by the commands that talk to real
 * services. The rule these tests circle is the fingerprint: a certification
 * is a fact about a configuration, and changing the bucket, endpoint or
 * worker address must silently un-certify — a reassuring timestamp for a
 * different service is worse than no timestamp at all.
 */
final class ProviderStandingsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/certs-'.uniqid());
        mkdir($this->root.'/framework', 0755, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->root.'/framework/certifications.json');
        @rmdir($this->root.'/framework');
        @rmdir($this->root);

        parent::tearDown();
    }

    // ------------------------------------------------------------- ledger

    #[Test]
    public function certified_is_only_answered_for_the_exact_fingerprint(): void
    {
        $ledger = $this->ledger();
        $ledger->record('storage:r2', 'fingerprint-one');

        $this->assertNotNull($ledger->certifiedAt('storage:r2', 'fingerprint-one'));
        $this->assertNull($ledger->certifiedAt('storage:r2', 'fingerprint-two'));
        $this->assertNull($ledger->certifiedAt('storage:s3', 'fingerprint-one'));
    }

    #[Test]
    public function re_recording_replaces_the_fingerprint_not_the_history_shape(): void
    {
        $ledger = $this->ledger();
        $ledger->record('worker', 'old-address');
        $ledger->record('worker', 'new-address');

        $this->assertNull($ledger->certifiedAt('worker', 'old-address'));
        $this->assertNotNull($ledger->certifiedAt('worker', 'new-address'));
    }

    // ---------------------------------------------------------- standings

    #[Test]
    public function a_fresh_install_is_honest_about_every_area(): void
    {
        $expected = [
            'object_storage' => CertificationStatus::NotConfigured,
            'worker' => CertificationStatus::NotConfigured,
            'ai' => CertificationStatus::NotConfigured,
            'transcription' => CertificationStatus::NotConfigured,
            'artwork' => CertificationStatus::NotConfigured,
            'music_generation' => CertificationStatus::Unavailable,
            'distributor' => CertificationStatus::CodeReady,
            'mail' => CertificationStatus::NotConfigured,
            'ddex' => CertificationStatus::BlockedExternal,
        ];

        $this->assertSame($expected, $this->statuses());
    }

    #[Test]
    public function nothing_on_a_fresh_install_can_be_certified(): void
    {
        // CERTIFIED is the one state a test environment must never produce
        // by accident: it exists only as the record of a real run.
        foreach ($this->assess() as $standing) {
            $this->assertNotSame(CertificationStatus::Certified, $standing->status, $standing->key);
        }
    }

    #[Test]
    public function configured_storage_without_a_run_is_uncertified_not_certified(): void
    {
        config(['storage.default' => 'r2']);

        $this->assertSame(CertificationStatus::ConfiguredUncertified, $this->statuses()['object_storage']);
    }

    #[Test]
    public function a_recorded_storage_run_certifies_and_a_changed_bucket_uncertifies(): void
    {
        config([
            'storage.default' => 'r2',
            'filesystems.disks.r2.bucket' => 'masters-bucket',
        ]);

        $this->ledger()->record('storage:r2', ProviderStandings::storageFingerprint('r2'));

        $this->assertSame(CertificationStatus::Certified, $this->statuses()['object_storage']);

        // Same provider name, different bucket: the record must stop
        // answering without anybody deleting anything.
        config(['filesystems.disks.r2.bucket' => 'somebody-elses-bucket']);

        $this->assertSame(CertificationStatus::ConfiguredUncertified, $this->statuses()['object_storage']);
    }

    #[Test]
    public function a_worker_certification_belongs_to_its_address(): void
    {
        config(['worker.url' => 'https://worker.internal:8443', 'worker.token' => 'a-token']);

        $this->ledger()->record('worker', ProviderStandings::workerFingerprint());
        $this->assertSame(CertificationStatus::Certified, $this->statuses()['worker']);

        config(['worker.url' => 'https://other-worker.internal:8443']);
        $this->assertSame(CertificationStatus::ConfiguredUncertified, $this->statuses()['worker']);
    }

    #[Test]
    public function an_ai_provider_named_without_a_credential_is_aspirational_not_configured(): void
    {
        config(['ai.default' => 'openai', 'ai.providers.openai.key' => '']);

        $standing = $this->standing('ai');

        $this->assertSame(CertificationStatus::NotConfigured, $standing->status);
        $this->assertStringContainsString('aspirational', $standing->detail);
    }

    #[Test]
    public function the_fake_generation_provider_is_code_ready_and_certifies_nothing(): void
    {
        config(['generation.default' => 'fake']);

        $standing = $this->standing('music_generation');

        $this->assertSame(CertificationStatus::CodeReady, $standing->status);
        $this->assertStringContainsString('certifies nothing', $standing->detail);
    }

    #[Test]
    public function no_fingerprint_ever_contains_a_secret(): void
    {
        config([
            'worker.url' => 'https://worker.internal:8443',
            'worker.token' => 'super-secret-token',
            'filesystems.disks.r2.bucket' => 'masters',
        ]);

        foreach ([ProviderStandings::workerFingerprint(), ProviderStandings::storageFingerprint('r2')] as $fingerprint) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $fingerprint);
            $this->assertStringNotContainsString('secret', $fingerprint);
        }
    }

    #[Test]
    public function a_real_smtp_mailer_is_uncertified_until_a_message_was_accepted(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.transport' => 'smtp', 'mail.mailers.smtp.host' => 'mail.example', 'mail.mailers.smtp.port' => 587]);

        $this->assertSame(CertificationStatus::ConfiguredUncertified, $this->statuses()['mail']);
    }

    #[Test]
    public function a_delivered_certification_message_certifies_and_a_changed_host_uncertifies(): void
    {
        $this->swapLedger();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.transport' => 'smtp', 'mail.mailers.smtp.host' => 'mail.example', 'mail.mailers.smtp.port' => 587]);

        Mail::fake();

        $this->artisan('sanitube:mail:certify', ['address' => 'operator@label.example'])
            ->expectsOutputToContain('accepted by the transport')
            ->assertSuccessful();

        $this->assertSame(CertificationStatus::Certified, $this->statuses()['mail']);

        // A different relay is a different fact; the record stops answering.
        config(['mail.mailers.smtp.host' => 'other-relay.example']);
        $this->assertSame(CertificationStatus::ConfiguredUncertified, $this->statuses()['mail']);
    }

    #[Test]
    public function a_mailer_that_delivers_nothing_has_nothing_to_certify(): void
    {
        $this->swapLedger();
        config(['mail.default' => 'log']);

        $this->artisan('sanitube:mail:certify', ['address' => 'operator@label.example'])
            ->expectsOutputToContain('does not deliver anything')
            ->assertFailed();
    }

    #[Test]
    public function a_non_address_is_refused_before_any_send(): void
    {
        Mail::fake();

        $this->artisan('sanitube:mail:certify', ['address' => 'not-an-address'])
            ->assertExitCode(2);

        Mail::assertNothingSent();
    }

    // ------------------------------------------------------------ command

    #[Test]
    public function the_command_reports_and_never_probes(): void
    {
        $this->swapLedger();

        $this->artisan('sanitube:providers')
            ->expectsOutputToContain('UNAVAILABLE')
            ->assertSuccessful();

        $this->artisan('sanitube:providers', ['--json' => true])
            ->expectsOutputToContain('"status": "BLOCKED_EXTERNAL"')
            ->assertSuccessful();
    }

    // ----------------------------------------------------------- fixtures

    private function ledger(): CertificationLedger
    {
        return new CertificationLedger($this->root);
    }

    private function swapLedger(): void
    {
        $this->app->bind(CertificationLedger::class, fn (): CertificationLedger => $this->ledger());
    }

    /**
     * @return list<ProviderStanding>
     */
    private function assess(): array
    {
        return (new ProviderStandings($this->ledger()))->assess();
    }

    /**
     * @return array<string, CertificationStatus>
     */
    private function statuses(): array
    {
        $statuses = [];

        foreach ($this->assess() as $standing) {
            $statuses[$standing->key] = $standing->status;
        }

        return $statuses;
    }

    private function standing(string $key): ProviderStanding
    {
        foreach ($this->assess() as $standing) {
            if ($standing->key === $key) {
                return $standing;
            }
        }

        $this->fail(sprintf('No standing named [%s].', $key));
    }
}
