<?php

declare(strict_types=1);

namespace Tests\Feature\Installer;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Deployment\Profiles\InstallationProfile;
use SaniTube\Installer\Services\InstallationJournal;
use SaniTube\Installer\StageResult;
use Tests\TestCase;

/**
 * The installer remembers, and remembering is all it does.
 *
 * DEP-008. An install that fails at stage four is resumed, not restarted, and
 * resuming needs a record of what happened. These tests hold the record's
 * three rules: it never authorises anything (stages re-check the machine), it
 * never carries a secret, and a corrupt journal is preserved rather than
 * replaced — the corrupt file is the one artefact that can explain itself.
 */
final class InstallationJournalTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/journal-'.uniqid());
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        // The fixture invents this directory; removing it is cleaning up after
        // ourselves, not deleting anything the repository tracks.
        $files = glob($this->root.'/installer/*') ?: [];

        foreach ($files as $file) {
            @unlink($file);
        }

        @rmdir($this->root.'/installer');
        @rmdir($this->root);

        parent::tearDown();
    }

    #[Test]
    public function every_outcome_is_recorded_failures_included(): void
    {
        $journal = $this->journal();

        $journal->record(StageResult::done('environment', 'Created .env from .env.example.'));
        $journal->record(StageResult::failed('database', 'The database did not answer.'));
        $journal->record(StageResult::skipped('owner', 'An active owner already exists.'));

        $stages = $journal->stages();

        $this->assertSame(StageResult::DONE, $stages['environment']['outcome']);
        $this->assertSame(StageResult::FAILED, $stages['database']['outcome']);
        $this->assertSame(StageResult::SKIPPED, $stages['owner']['outcome']);
    }

    #[Test]
    public function a_stage_run_again_keeps_only_its_latest_outcome(): void
    {
        $journal = $this->journal();

        $journal->record(StageResult::failed('database', 'The database did not answer.'));
        $journal->record(StageResult::done('database', 'Connected using the [mysql] connection.'));

        $this->assertSame(StageResult::DONE, $journal->stages()['database']['outcome']);
        $this->assertCount(1, $journal->stages());
    }

    #[Test]
    public function the_first_runs_start_survives_later_runs(): void
    {
        $journal = $this->journal();
        $journal->record(StageResult::done('preflight'));

        $started = $journal->startedAt();
        $this->assertNotNull($started);

        $journal->record(StageResult::done('migrate'));
        $this->assertSame($started, $journal->startedAt());
    }

    #[Test]
    public function the_journal_file_is_not_world_readable(): void
    {
        $journal = $this->journal();
        $journal->record(StageResult::done('preflight'));

        $mode = fileperms($this->root.'/installer/journal.json') & 0777;

        $this->assertSame(0600, $mode, sprintf('journal.json is mode %o.', $mode));
    }

    #[Test]
    public function a_detail_carrying_an_address_is_scrubbed_before_it_is_written(): void
    {
        // Details are prose and should never carry a credential — and they go
        // through the same redaction rule as every failure message anyway,
        // because "should never" is not a control.
        $journal = $this->journal();
        $journal->record(StageResult::failed('database', 'Refused by mysql://root:hunter2@db.internal:3306.'));

        $raw = (string) file_get_contents($this->root.'/installer/journal.json');

        $this->assertStringNotContainsString('hunter2', $raw);
        $this->assertStringNotContainsString('db.internal', $raw);
    }

    #[Test]
    public function a_corrupt_journal_is_preserved_aside_not_replaced(): void
    {
        mkdir($this->root.'/installer', 0700, true);
        file_put_contents($this->root.'/installer/journal.json', '{not json at all');

        $journal = $this->journal();
        $journal->record(StageResult::done('preflight'));

        $this->assertFileExists($this->root.'/installer/journal.corrupt.json');
        $this->assertSame('{not json at all', file_get_contents($this->root.'/installer/journal.corrupt.json'));

        // And a fresh journal carries on in its place.
        $this->assertSame(StageResult::DONE, $journal->stages()['preflight']['outcome']);
    }

    #[Test]
    public function a_profile_is_remembered_and_an_unknown_one_is_not_guessed_at(): void
    {
        $journal = $this->journal();

        $this->assertNull($journal->recordedProfile());

        $journal->rememberProfile(InstallationProfile::Cpanel);
        $this->assertSame(InstallationProfile::Cpanel, $journal->recordedProfile());

        // A journal written by a build that knew more profiles than this one
        // answers null, not an exception: the value is advisory, and advice
        // in a vocabulary nobody speaks is no advice.
        $raw = (string) file_get_contents($this->root.'/installer/journal.json');
        file_put_contents($this->root.'/installer/journal.json', str_replace('CPANEL', 'FUTURE_SHAPE', $raw));

        $this->assertNull($this->journal()->recordedProfile());
    }

    // ------------------------------------------------------------ command

    #[Test]
    public function the_install_command_refuses_a_profile_it_does_not_know_by_name(): void
    {
        $this->artisan('sanitube:install', ['--profile' => 'KUBERNETES', '--skip-owner' => true])
            ->expectsOutputToContain('Unknown profile [KUBERNETES]')
            ->assertFailed();
    }

    #[Test]
    public function the_install_command_refuses_worker_only_because_it_installs_core(): void
    {
        $this->artisan('sanitube:install', ['--profile' => 'WORKER_ONLY', '--skip-owner' => true])
            ->expectsOutputToContain('does not install Core')
            ->assertFailed();
    }

    #[Test]
    public function a_profiled_install_records_its_stages_and_speaks_the_profiles_language(): void
    {
        $this->swapJournalPath();

        $this->artisan('sanitube:install', ['--profile' => 'cpanel', '--skip-owner' => true])
            ->expectsOutputToContain('schedule:run')
            ->assertSuccessful();

        $journal = $this->app->make(InstallationJournal::class);

        $this->assertSame(InstallationProfile::Cpanel, $journal->recordedProfile());
        $this->assertArrayHasKey('preflight', $journal->stages());
        $this->assertArrayHasKey('migrations', $journal->stages());
    }

    #[Test]
    public function reprofiling_warns_instead_of_pretending_the_old_services_vanish(): void
    {
        $this->swapJournalPath();

        $this->artisan('sanitube:install', ['--profile' => 'CPANEL', '--skip-owner' => true])->assertSuccessful();

        $this->artisan('sanitube:install', ['--profile' => 'VPS_CORE', '--skip-owner' => true])
            ->expectsOutputToContain('previously profiled CPANEL')
            ->assertSuccessful();

        $this->assertSame(
            InstallationProfile::VpsCore,
            $this->app->make(InstallationJournal::class)->recordedProfile(),
        );
    }

    #[Test]
    public function status_reports_without_running_a_single_stage(): void
    {
        $this->swapJournalPath();

        $this->artisan('sanitube:install', ['--status' => true])
            ->expectsOutputToContain('No installation has been recorded')
            ->assertSuccessful();

        $this->artisan('sanitube:install', ['--profile' => 'CPANEL', '--skip-owner' => true])->assertSuccessful();

        $this->artisan('sanitube:install', ['--status' => true])
            ->expectsOutputToContain('CPANEL')
            ->assertSuccessful();
    }

    // ----------------------------------------------------------- fixtures

    private function journal(): InstallationJournal
    {
        return new InstallationJournal($this->root);
    }

    private function swapJournalPath(): void
    {
        $this->app->bind(
            InstallationJournal::class,
            fn (): InstallationJournal => new InstallationJournal($this->root),
        );
    }
}
