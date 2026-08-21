<?php

declare(strict_types=1);

namespace Tests\Feature\Deployment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Deployment\ProductionCheck;
use SaniTube\Deployment\Services\CreateBackup;
use SaniTube\Deployment\Services\DiagnoseProduction;
use SaniTube\Observability\SchedulerHeartbeat;
use Tests\TestCase;

/**
 * Is this installation fit to be live?
 *
 * A different question from `sanitube:health`, which reports what the *machine*
 * can do and asks the same thing in development as in production. Almost every
 * check here passes on a laptop and is serious on a public host, which is why
 * the two commands stay separate.
 *
 * The claims:
 *
 *   - **It changes nothing.** An operator runs this on a live server at the
 *     moment they are least willing to have something else happen.
 *   - **It never prints a secret.** A diagnostic is exactly the output somebody
 *     pastes into a support thread.
 *   - **UNKNOWN is never a pass.** An installation whose database cannot be
 *     reached does not have a healthy schema; it has no answer.
 *   - **The exit code is the point**, so a deploy script can gate on it.
 */
final class DoctorTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------- what it refuses

    #[Test]
    public function debug_mode_in_production_is_a_blocker(): void
    {
        // The single most consequential production setting in Laravel, and the
        // one most often left as the framework ships it: it renders stack
        // traces, environment variables and database credentials to anyone who
        // can trigger an error.
        config(['app.env' => 'production', 'app.debug' => true]);

        $this->assertBlocks('app_debug');
    }

    #[Test]
    public function debug_mode_outside_production_is_only_worth_mentioning(): void
    {
        config(['app.env' => 'local', 'app.debug' => true]);

        $this->assertSame(ProductionCheck::WARNING, $this->check('app_debug')->verdict);
    }

    #[Test]
    public function a_missing_application_key_is_a_blocker(): void
    {
        config(['app.key' => '']);

        $this->assertBlocks('app_key');
    }

    #[Test]
    public function a_production_url_that_is_not_https_is_a_blocker(): void
    {
        // Session cookies and signed preview links travel over it.
        config(['app.env' => 'production', 'app.url' => 'http://sanitube.example']);

        $this->assertBlocks('app_url');
    }

    #[Test]
    public function a_production_url_still_pointing_at_localhost_is_a_blocker(): void
    {
        config(['app.env' => 'production', 'app.url' => 'http://localhost']);

        $this->assertBlocks('app_url');
    }

    #[Test]
    public function a_queue_that_runs_work_inline_is_a_blocker(): void
    {
        // On `sync`, importing nine hundred files happens inside one web
        // request. It does not half-work; it times out.
        config(['queue.default' => 'sync']);

        $this->assertBlocks('driver');
    }

    #[Test]
    public function backups_written_inside_the_web_root_are_a_blocker(): void
    {
        // A backup there is the whole database available over HTTP.
        config(['backup.destination' => public_path('backups')]);

        $this->assertBlocks('backup_destination');
    }

    // ------------------------------------------------------- what it accepts

    #[Test]
    public function a_correctly_configured_installation_reports_no_blockers(): void
    {
        $this->configureForProduction();

        foreach ($this->diagnose() as $check) {
            // Server capabilities are the machine's business and are allowed
            // to be missing here — FFmpeg is not installed in CI. Everything
            // this command is *for* has to pass.
            if ($check->section === 'Server') {
                continue;
            }

            $this->assertNotSame(
                ProductionCheck::BLOCKER,
                $check->verdict,
                sprintf('[%s] blocks a correctly configured installation: %s', $check->key, $check->summary),
            );
        }
    }

    #[Test]
    public function a_scheduler_that_has_never_run_is_never_reported_as_healthy(): void
    {
        // Never is not zero. A scheduler that has not run is not one that ran
        // and found nothing to do.
        $check = $this->check('heartbeat');

        $this->assertNotSame(ProductionCheck::READY, $check->verdict);
    }

    #[Test]
    public function a_scheduler_that_has_just_run_is_healthy(): void
    {
        $this->app->make(SchedulerHeartbeat::class)->record();

        $this->assertSame(ProductionCheck::READY, $this->check('heartbeat')->verdict);
    }

    /**
     * Freshness says when the last backup was. This says whether there will be
     * another.
     *
     * DEP-005 refuses a bad include path *at backup time*, which on a shared
     * account is a cron line failing quietly at three in the morning. The
     * operator's first sign would be the freshness warning a week later, and by
     * then they have a week of work to re-do.
     */
    /**
     * A queue nobody works accepts everything and completes nothing.
     *
     * The driver check answers "is work queued rather than run inline". It does
     * not answer "does anybody pick it up", and those fail separately: both
     * deployment guides tell an operator to add a `queue:work` cron or systemd
     * unit, and until now nothing checked that they did. Imports and analysis
     * are accepted, queued, and silently never finish.
     */
    #[Test]
    public function work_nobody_has_picked_up_for_a_long_time_is_reported(): void
    {
        $this->queue(waitedSeconds: 3600);

        $check = $this->check('backlog');

        $this->assertSame(ProductionCheck::WARNING, $check->verdict);
        $this->assertStringContainsString('60 minute', $check->summary);
        $this->assertNotNull($check->remediation);
    }

    #[Test]
    public function work_queued_a_moment_ago_is_not_a_backlog(): void
    {
        // The other half. A large import legitimately queues a burst, and a
        // check that called that a dead queue would be one an operator learns
        // to ignore.
        $this->queue(waitedSeconds: 5);

        $this->assertSame(ProductionCheck::READY, $this->check('backlog')->verdict);
    }

    #[Test]
    public function a_job_a_worker_is_holding_is_a_worker_doing_its_job(): void
    {
        // Reserved, and waiting a long time. That is a slow job, not an absent
        // worker, and reporting it as a backlog would send somebody to restart
        // a worker that is running.
        $this->queue(waitedSeconds: 3600, reserved: true);

        $this->assertSame(ProductionCheck::READY, $this->check('backlog')->verdict);
    }

    #[Test]
    public function work_that_is_not_due_yet_is_not_a_backlog(): void
    {
        // A delayed job is waiting because it was told to. `available_at` in
        // the future is the queue behaving.
        $this->queue(waitedSeconds: -3600);

        $this->assertSame(ProductionCheck::READY, $this->check('backlog')->verdict);
    }

    #[Test]
    public function a_queue_this_check_cannot_see_is_not_reported_as_empty(): void
    {
        // Redis and SQS keep their own work. Reading the unused `jobs` table
        // would report an empty queue on an installation whose real one is
        // backed up, which is worse than saying nothing.
        $this->queue(waitedSeconds: 3600);
        config(['queue.default' => 'sync']);

        $keys = array_map(
            static fn (ProductionCheck $check): string => $check->key,
            $this->diagnose(),
        );

        $this->assertNotContains('backlog', $keys);
    }

    #[Test]
    public function the_oldest_waiting_job_is_the_one_reported(): void
    {
        // Two jobs, and the answer is about the one that has waited longest.
        // With a single row every reduction looks the same — first, last,
        // newest, oldest — so this is the test that makes `min` mean `min`.
        $this->queue(waitedSeconds: 30);
        $this->queue(waitedSeconds: 7200);

        $check = $this->check('backlog');

        $this->assertSame(ProductionCheck::WARNING, $check->verdict);
        $this->assertStringContainsString('120 minute', $check->summary);
    }

    /**
     * The failure the backlog check cannot see.
     *
     * A worker consuming briskly and failing every job leaves `jobs` empty, so
     * the backlog reads READY while the catalogue quietly stops growing.
     * "Nobody is working the queue" and "everything the queue does fails" look
     * identical from a job count and are opposite problems.
     */
    #[Test]
    public function work_that_failed_and_nobody_has_looked_at_is_reported(): void
    {
        $this->failedJob();

        $check = $this->check('failed_work');

        $this->assertSame(ProductionCheck::WARNING, $check->verdict);
        $this->assertStringContainsString('1 job', $check->summary);
        $this->assertNotNull($check->remediation);
    }

    #[Test]
    public function an_installation_with_nothing_failing_says_so(): void
    {
        $this->assertSame(ProductionCheck::READY, $this->check('failed_work')->verdict);
    }

    #[Test]
    public function a_backlog_check_that_is_ready_does_not_mean_nothing_is_wrong(): void
    {
        // Both at once, and they must disagree. An empty `jobs` table with a
        // full `failed_jobs` is the exact shape this pair exists to separate.
        $this->failedJob();
        $this->failedJob();

        $this->assertSame(ProductionCheck::WARNING, $this->check('failed_work')->verdict);
        $this->assertStringContainsString('2 job', $this->check('failed_work')->summary);
    }

    #[Test]
    public function discarding_failures_is_reported_rather_than_read_as_none(): void
    {
        // On the `null` driver a failure leaves no trace at all, and counting
        // an empty table would report a healthy queue on an installation that
        // cannot tell anybody what did not happen.
        config(['queue.failed.driver' => 'null']);

        $check = $this->check('failed_work');

        $this->assertSame(ProductionCheck::WARNING, $check->verdict);
        $this->assertStringContainsString('discarded', $check->summary);
    }

    private function failedJob(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid7(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'SaniTube\\Media\\Jobs\\AnalyzeAudioAssetJob']),
            'exception' => 'RuntimeException: the provider refused',
            'failed_at' => now(),
        ]);
    }

    /**
     * Degraded is not optional.
     *
     * Every detector that reports a degraded capability attaches a remediation,
     * and the doctor was dropping all of them: it reported only capabilities
     * that were *unavailable and required*. An installation meaning to use R2
     * and running on the local disk is told "cannot issue expiring URLs" by
     * `sanitube:health` and nothing at all by the command somebody runs before
     * going live.
     */
    #[Test]
    public function a_degraded_capability_reaches_the_doctor(): void
    {
        // The shipped default on a host with no object storage: it works, and
        // it cannot issue an expiring URL, which is what a distributor hand-off
        // and streaming both need.
        config(['storage.default' => 'local']);

        $check = $this->check('object_storage');

        $this->assertSame(ProductionCheck::WARNING, $check->verdict);
        $this->assertStringContainsString('expiring URLs', $check->summary);
        $this->assertNotNull($check->remediation, 'A degraded capability carries the fix; the doctor must carry it too.');
    }

    #[Test]
    public function a_capability_the_doctor_asks_about_itself_is_not_said_twice(): void
    {
        // The queue is degraded on `sync` *and* has its own blocker here. Two
        // lines for one problem is how an operator learns to skim, and this
        // command's own comment warns that a second opinion can quietly start
        // disagreeing with the guard it duplicates.
        config(['queue.default' => 'sync']);

        $keys = array_map(
            static fn (ProductionCheck $check): string => $check->key,
            $this->diagnose(),
        );

        $this->assertContains('driver', $keys, 'The queue problem must still be reported once.');
        $this->assertNotContains('queue', $keys);
    }

    #[Test]
    public function every_capability_the_doctor_defers_on_is_one_it_actually_answers(): void
    {
        // An exemption for a question nobody asks is a silence with no owner.
        // Read from the doctor rather than from a second list.
        config(['queue.default' => 'sync']);

        $keys = array_map(
            static fn (ProductionCheck $check): string => $check->key,
            $this->diagnose(),
        );

        $this->assertContains('driver', $keys);
        $this->assertContains('heartbeat', $keys);
    }

    /**
     * One row in the queue table, ready this long ago.
     */
    private function queue(int $waitedSeconds, bool $reserved = false): void
    {
        // The production shape. The suite runs on `sync`, where there is no
        // queue to be behind on and the check correctly says nothing at all.
        config(['queue.default' => 'database']);

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'SaniTube\\Media\\Jobs\\AnalyzeAudioAssetJob']),
            'attempts' => 0,
            'reserved_at' => $reserved ? time() : null,
            'available_at' => time() - $waitedSeconds,
            'created_at' => time() - $waitedSeconds,
        ]);
    }

    #[Test]
    public function a_backup_configuration_that_can_never_run_is_reported_before_it_fails(): void
    {
        config(['backup.include_paths' => ['..']]);

        $check = $this->check('include_paths');

        $this->assertSame(ProductionCheck::WARNING, $check->verdict);
        $this->assertStringContainsString('..', $check->summary);
        $this->assertNotNull($check->remediation);
    }

    #[Test]
    public function a_workable_backup_configuration_says_nothing_at_all(): void
    {
        // No check at all rather than a green one. The doctor's output is read
        // by somebody looking for what is wrong, and a line per setting that
        // is fine is how the two lines that are not get scrolled past.
        $keys = array_map(
            static fn (ProductionCheck $check): string => $check->key,
            $this->diagnose(),
        );

        $this->assertNotContains('include_paths', $keys);
    }

    #[Test]
    public function an_installation_that_has_never_been_backed_up_is_never_reported_as_healthy(): void
    {
        $this->assertNotSame(ProductionCheck::READY, $this->check('freshness')->verdict);
    }

    #[Test]
    public function a_backup_taken_just_now_is_reported_as_fresh(): void
    {
        $destination = storage_path('framework/testing/doctor-'.bin2hex(random_bytes(4)));
        config(['backup.destination' => $destination]);

        try {
            $this->app->make(CreateBackup::class)->handle('doctor');

            $this->assertSame(ProductionCheck::READY, $this->check('freshness')->verdict);
        } finally {
            $this->rmrf($destination);
        }
    }

    private function rmrf(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..' || ! is_string($entry)) {
                continue;
            }

            $full = $path.'/'.$entry;

            is_dir($full) && ! is_link($full) ? $this->rmrf($full) : unlink($full);
        }

        rmdir($path);
    }

    // ------------------------------------------------------------- discipline

    #[Test]
    public function it_never_prints_a_secret(): void
    {
        // A diagnostic is exactly the output somebody pastes into a support
        // thread. Checks report whether a value is *configured*, never what it
        // is — including truncated or masked, which is still more than nobody
        // needed.
        config([
            'app.key' => 'base64:S3CR3TAPPLICATIONKEYVALUE0000000000000000000=',
            'database.connections.sqlite.password' => 'the-database-password',
            'app.env' => 'production',
            // The assisted features report *which provider* is configured,
            // which is a choice an operator made — never its key, which is not.
            'transcription.default' => 'openai',
            'transcription.providers.openai.key' => 'sk-transcription-should-never-appear',
            'ai.default' => 'openai',
            'ai.providers.openai.key' => 'sk-enrichment-should-never-appear',
            'artwork.default_provider' => 'openai',
            'artwork.providers.openai.key' => 'sk-artwork-should-never-appear',
        ]);

        $rendered = json_encode(
            array_map(static fn (ProductionCheck $c): array => $c->toArray(), $this->diagnose()),
            JSON_THROW_ON_ERROR,
        );

        $this->assertStringNotContainsString('S3CR3TAPPLICATIONKEY', $rendered);
        $this->assertStringNotContainsString('the-database-password', $rendered);
        $this->assertStringNotContainsString('sk-transcription-should-never-appear', $rendered);
        $this->assertStringNotContainsString('sk-enrichment-should-never-appear', $rendered);
        $this->assertStringNotContainsString('sk-artwork-should-never-appear', $rendered);

        // And the provider names *are* there, or the assertions above would
        // pass on output that simply never mentioned these features.
        $this->assertStringContainsString('openai', $rendered);
    }

    #[Test]
    public function an_absent_optional_provider_is_never_a_blocker(): void
    {
        // The failure the whole capability model exists to avoid: a
        // correctly-configured shared host with no AI account must not look
        // broken. Every one of these features is optional by design.
        config([
            'transcription.default' => 'none',
            'ai.default' => 'none',
            'artwork.default_provider' => 'none',
        ]);

        foreach (['provider', 'generation'] as $key) {
            foreach ($this->diagnose() as $check) {
                if ($check->key === $key) {
                    $this->assertFalse($check->isBlocking(), $key.' blocked on an optional feature.');
                }
            }
        }
    }

    #[Test]
    public function an_image_provider_that_cannot_meet_the_requirement_is_reported(): void
    {
        // ART-002's mismatch, made visible. A provider can be configured,
        // credentialled and working and still be unable to satisfy this
        // installation's own artwork requirements. Unreported, that is
        // discovered as a refusal at the moment somebody wanted a cover.
        config([
            'artwork.default_provider' => 'openai',
            'artwork.providers.openai.sizes' => ['1024x1024'],
            'artwork.requirements.minimum_pixels' => 3000,
        ]);

        $check = $this->check('generation');

        $this->assertFalse($check->isBlocking(), 'A size mismatch is a warning, not a blocker.');
        $this->assertStringContainsString('1024', $check->summary);
        $this->assertStringContainsString('3000', $check->summary);

        // Says which way to resolve it, rather than only that something is
        // wrong. Both directions are legitimate.
        $this->assertNotNull($check->remediation);
        $this->assertStringContainsString('lower', strtolower((string) $check->remediation));
    }

    #[Test]
    public function an_image_provider_that_can_meet_the_requirement_is_not_warned_about(): void
    {
        config([
            'artwork.default_provider' => 'openai',
            'artwork.providers.openai.sizes' => ['1024x1024', '4000x4000'],
            'artwork.requirements.minimum_pixels' => 3000,
        ]);

        $check = $this->check('generation');

        $this->assertStringNotContainsString('cannot', strtolower($check->summary));
    }

    #[Test]
    public function an_oblong_size_is_judged_on_its_shortest_side(): void
    {
        // The case square fixtures cannot catch, and a mutation found it: with
        // only square sizes configured, taking the longest edge and taking the
        // shortest are the same number. A provider offering 1792x1024 has a
        // shortest side of 1024, and reading the 1792 would report that it can
        // meet a 1500px requirement it cannot meet — a cover is square, so the
        // short edge is the one that has to clear the bar.
        config([
            'artwork.default_provider' => 'openai',
            'artwork.providers.openai.sizes' => ['1792x1024'],
            'artwork.requirements.minimum_pixels' => 1500,
        ]);

        $check = $this->check('generation');

        $this->assertStringContainsString('1024', $check->summary);
        $this->assertStringNotContainsString('1792', $check->summary);
    }

    #[Test]
    public function the_doctor_confirms_that_nothing_releases_unattended(): void
    {
        // Reported as reassurance rather than a problem, and reported at all
        // because it is the thing an operator most needs to confirm without
        // reading the source. If AutonomousRelease is ever unlocked, this
        // becomes a blocker on a live server.
        $check = $this->check('unattended_release');

        $this->assertFalse($check->isBlocking());
        $this->assertStringContainsString('without a person', $check->summary);
    }

    #[Test]
    public function it_changes_nothing(): void
    {
        // Read-only is not a style preference here: this is the command run on
        // a server nobody wants anything else to happen to.
        $this->app->make(SchedulerHeartbeat::class)->record();

        $before = $this->app->make(SchedulerHeartbeat::class)->lastRunAt();
        $backupsBefore = is_dir(storage_path('backups')) ? scandir(storage_path('backups')) : null;

        $this->diagnose();

        $this->assertEquals($before, $this->app->make(SchedulerHeartbeat::class)->lastRunAt());
        $this->assertEquals(
            $backupsBefore,
            is_dir(storage_path('backups')) ? scandir(storage_path('backups')) : null,
        );
    }

    #[Test]
    public function the_command_exits_non_zero_when_something_blocks(): void
    {
        config(['app.env' => 'production', 'app.debug' => true]);

        $this->artisan('sanitube:doctor')->assertFailed();
    }

    #[Test]
    public function the_command_answers_as_json_for_a_deploy_script(): void
    {
        config(['app.env' => 'production', 'app.debug' => true]);

        $this->artisan('sanitube:doctor', ['--json' => true])
            ->expectsOutputToContain('"verdict": "BLOCKER"')
            ->assertFailed();
    }

    // ---------------------------------------------------------------- disk

    #[Test]
    public function a_roomy_disk_is_ready_and_says_how_roomy(): void
    {
        // Thresholds at zero disable the verdicts; the real machine running
        // this test has whatever space it has, so the assertion is about the
        // shape: both volumes reported, neither blocking.
        config(['operations.disk.warn_below_mb' => 0, 'operations.disk.blocker_below_mb' => 0]);

        $check = $this->check('application_disk');

        $this->assertSame(ProductionCheck::READY, $check->verdict);
        $this->assertMatchesRegularExpression('/\d+ MB free/', $check->summary);
    }

    #[Test]
    public function a_disk_below_the_warning_threshold_warns_with_both_numbers(): void
    {
        // A warn threshold no real machine satisfies, so this machine is
        // "low" by construction — the verdict logic is what is under test.
        config(['operations.disk.warn_below_mb' => PHP_INT_MAX, 'operations.disk.blocker_below_mb' => 0]);

        $check = $this->check('application_disk');

        $this->assertSame(ProductionCheck::WARNING, $check->verdict);
        $this->assertStringContainsString('warning threshold', $check->summary);
    }

    #[Test]
    public function a_disk_below_the_blocker_threshold_blocks_the_deploy(): void
    {
        config(['operations.disk.blocker_below_mb' => PHP_INT_MAX]);

        $check = $this->check('application_disk');

        $this->assertSame(ProductionCheck::BLOCKER, $check->verdict);
        $this->assertStringContainsString('fails halfway', $check->summary);
    }

    #[Test]
    public function the_backup_volume_is_judged_separately_when_it_exists(): void
    {
        $destination = storage_path('framework/testing/doctor-disk-'.uniqid());
        mkdir($destination, 0755, true);
        config(['backup.destination' => $destination, 'operations.disk.warn_below_mb' => 0, 'operations.disk.blocker_below_mb' => 0]);

        $this->assertSame(ProductionCheck::READY, $this->check('backup_disk')->verdict);

        rmdir($destination);
    }

    // -------------------------------------------------------------- fixtures

    private function configureForProduction(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.url' => 'https://sanitube.example',
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'queue.default' => 'database',
            'backup.destination' => storage_path('framework/testing/doctor-backups'),
        ]);
    }

    /**
     * @return list<ProductionCheck>
     */
    private function diagnose(): array
    {
        return $this->app->make(DiagnoseProduction::class)->handle();
    }

    private function check(string $key): ProductionCheck
    {
        foreach ($this->diagnose() as $check) {
            if ($check->key === $key) {
                return $check;
            }
        }

        $this->fail(sprintf('No check named [%s].', $key));
    }

    private function assertBlocks(string $key): void
    {
        $check = $this->check($key);

        $this->assertSame(ProductionCheck::BLOCKER, $check->verdict, $check->summary);

        // A finding without a fix is a diagnosis somebody still has to go and
        // research. Every blocker carries what to do about it.
        $this->assertNotNull($check->remediation);
        $this->assertNotSame('', $check->remediation);
    }
}
