<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Services\AssetStorageService;
use SaniTube\Deployment\ProductionCheck;
use SaniTube\Deployment\Services\DiagnoseProduction;
use SaniTube\Media\Contracts\AudioFingerprinter;
use SaniTube\Media\Jobs\FingerprintAssetJob;
use SaniTube\Media\Services\FingerprintAsset;
use SaniTube\Media\Testing\FakeAudioFingerprinter;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * MED-004. Fingerprinting a library stored before the host could fingerprint.
 *
 * **The gap this closes produced no error anywhere, which is what made it
 * serious.** A fingerprint is taken when a candidate appears, and only then. An
 * installation that imported four thousand masters on a shared account with no
 * Chromaprint got none — correctly, because an absent tool is a supported
 * configuration rather than a failure. When that installation later gained the
 * capability — a static `fpcalc` uploaded, or a worker attached, which WRK-002
 * made an ordinary thing to do — **nothing went back over what was already
 * there.**
 *
 * Acoustic duplicate detection then covered none of the catalogue, for ever,
 * and every screen reported exactly what it had reported before. No exception,
 * no failed job, no red anything. These tests are the two halves of the fix:
 * something that does the work, and something that says the work is owed.
 */
final class FingerprintBacklogTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryStorageProvider $store;

    private FakeAudioFingerprinter $fingerprinter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new InMemoryStorageProvider('memory');
        config(['storage.default' => 'memory']);
        $this->app->make(StorageManager::class)->register('memory', $this->store);

        $this->fingerprinter = new FakeAudioFingerprinter;
        $this->app->instance(AudioFingerprinter::class, $this->fingerprinter);

        Queue::fake();
    }

    // ---------------------------------------------------------- the sweep

    /**
     * The scenario, end to end: a catalogue imported without the tool, and the
     * tool arriving afterwards.
     */
    #[Test]
    public function a_library_stored_before_the_tool_existed_is_queued_once_it_does(): void
    {
        $this->fingerprinter->unavailable();

        $assets = [
            $this->asset('one.wav', 'first master'),
            $this->asset('two.wav', 'second master'),
            $this->asset('three.wav', 'third master'),
        ];

        // Nothing was fingerprinted, and nothing failed. That is the correct
        // behaviour of the host they were imported on.
        $this->artisan('sanitube:media:fingerprint')
            ->expectsOutputToContain('cannot take an acoustic fingerprint')
            ->assertSuccessful();

        Queue::assertNothingPushed();

        // The tool arrives.
        $this->fingerprinter = new FakeAudioFingerprinter;
        $this->app->instance(AudioFingerprinter::class, $this->fingerprinter);

        $this->artisan('sanitube:media:fingerprint')->assertSuccessful();

        Queue::assertPushed(FingerprintAssetJob::class, count($assets));
    }

    /**
     * An absent tool is not an error.
     *
     * A scheduled command that fails nightly on a correctly configured shared
     * host is one whose output stops being read — and then the night it means
     * something, nobody looks.
     */
    #[Test]
    public function a_host_that_cannot_fingerprint_is_not_a_failure(): void
    {
        $this->fingerprinter->unavailable();

        $this->artisan('sanitube:media:fingerprint')->assertSuccessful();
    }

    #[Test]
    public function an_asset_already_fingerprinted_by_this_tool_is_not_queued_again(): void
    {
        $asset = $this->asset('one.wav', 'first master');

        $this->artisan('sanitube:media:fingerprint')->assertSuccessful();
        Queue::assertPushed(FingerprintAssetJob::class, 1);

        // As the queued job would have.
        $this->app->make(FingerprintAsset::class)->handle($asset);

        $this->artisan('sanitube:media:fingerprint')
            ->expectsOutputToContain('Every stored master has been fingerprinted')
            ->assertSuccessful();

        // Still one: the second run added nothing.
        Queue::assertPushed(FingerprintAssetJob::class, 1);
    }

    /**
     * A trashed master is not work that is owed.
     *
     * It was set aside by a named person with a reason, and fingerprinting it
     * would spend a worker's time to make a file somebody rejected comparable.
     */
    #[Test]
    public function a_trashed_master_is_not_queued(): void
    {
        $asset = $this->asset('one.wav', 'first master');
        $asset->forceFill(['trashed_at' => now()])->save();

        $this->artisan('sanitube:media:fingerprint')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    /**
     * The limit is honoured, and what it left behind is said out loud.
     *
     * A truncated sweep that reads like a complete one is worse than no sweep:
     * an operator runs it once, sees no complaint, and concludes the library
     * is comparable.
     */
    #[Test]
    public function the_limit_is_honoured_and_the_remainder_is_reported(): void
    {
        for ($index = 1; $index <= 5; $index++) {
            $this->asset(sprintf('%d.wav', $index), 'master '.$index);
        }

        $this->artisan('sanitube:media:fingerprint', ['--limit' => 2])
            ->expectsOutputToContain('3 still outstanding')
            ->assertSuccessful();

        Queue::assertPushed(FingerprintAssetJob::class, 2);
    }

    #[Test]
    public function a_dry_run_queues_nothing(): void
    {
        $this->asset('one.wav', 'first master');

        $this->artisan('sanitube:media:fingerprint', ['--dry-run' => true])
            ->expectsOutputToContain('would be queued')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    /**
     * A new algorithm version makes the whole library outstanding again.
     *
     * Which is the intended behaviour: fingerprints from different versions are
     * not reliably comparable, and a recalibration that silently considered
     * itself done would leave a library measured half one way and half the
     * other.
     */
    #[Test]
    public function a_new_algorithm_version_makes_everything_outstanding_again(): void
    {
        $asset = $this->asset('one.wav', 'first master');
        $this->app->make(FingerprintAsset::class)->handle($asset);

        $this->artisan('sanitube:media:fingerprint')
            ->expectsOutputToContain('Every stored master has been fingerprinted')
            ->assertSuccessful();

        $this->app->instance(AudioFingerprinter::class, new FakeAudioFingerprinter(version: '2'));

        $this->artisan('sanitube:media:fingerprint')->assertSuccessful();

        Queue::assertPushed(FingerprintAssetJob::class, 1);
    }

    // --------------------------------------------------------- the doctor

    /**
     * The half that matters more: something says the work is owed.
     *
     * A backlog command nobody knows to run is the same as no command, and the
     * whole point of this ticket is that the gap announced itself nowhere.
     */
    #[Test]
    public function the_doctor_reports_a_library_that_has_never_been_fingerprinted(): void
    {
        $this->asset('one.wav', 'first master');
        $this->asset('two.wav', 'second master');

        $check = $this->deduplicationCheck();

        $this->assertSame(ProductionCheck::WARNING, $check->verdict);
        $this->assertStringContainsString('2', $check->summary);
        $this->assertStringContainsString('sanitube:media:fingerprint', (string) $check->remediation);
    }

    /**
     * And never blocks going live. Detection by checksum keeps working, and an
     * installation that has deliberately never fingerprinted anything is a
     * shared account doing what it can — not a misconfiguration.
     */
    #[Test]
    public function the_finding_never_blocks_a_deployment(): void
    {
        $this->asset('one.wav', 'first master');

        $this->assertFalse($this->deduplicationCheck()->isBlocking());
    }

    #[Test]
    public function the_doctor_says_when_the_host_cannot_fingerprint_at_all(): void
    {
        $this->fingerprinter->unavailable();
        $this->asset('one.wav', 'first master');

        $check = $this->deduplicationCheck();

        // Ready, not warning: nothing is owed on a host that cannot do it.
        // Said anyway, because "duplicates are checked" and "duplicates are
        // checked by content hash only" are different promises.
        $this->assertSame(ProductionCheck::READY, $check->verdict);
        $this->assertStringContainsString('checksum only', $check->summary);
    }

    #[Test]
    public function the_doctor_is_satisfied_once_the_library_is_covered(): void
    {
        $asset = $this->asset('one.wav', 'first master');
        $this->app->make(FingerprintAsset::class)->handle($asset);

        $this->assertSame(ProductionCheck::READY, $this->deduplicationCheck()->verdict);
    }

    // ------------------------------------------------------------ fixtures

    private function deduplicationCheck(): ProductionCheck
    {
        foreach ($this->app->make(DiagnoseProduction::class)->handle() as $check) {
            if ($check->section === 'Deduplication') {
                return $check;
            }
        }

        $this->fail('The doctor published no deduplication finding.');
    }

    private function asset(string $filename, string $contents): Asset
    {
        $assets = $this->app->make(AssetStorageService::class);

        return $assets->store($assets->register(AssetKind::AudioMaster, $filename), $this->streamOf($contents));
    }

    /**
     * @return resource
     */
    private function streamOf(string $contents)
    {
        $stream = fopen('php://temp', 'r+b');

        if ($stream === false) {
            $this->fail('Could not open a temporary stream.');
        }

        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }
}
