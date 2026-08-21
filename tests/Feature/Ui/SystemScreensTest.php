<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Deployment\Services\CreateBackup;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Observability\Health\OperationalHealth;
use SaniTube\Observability\Health\OperationalHealthStore;
use SaniTube\Observability\SchedulerHeartbeat;
use SaniTube\Ui\Navigation\NavigationTree;
use SaniTube\Ui\Queries\OperationsQuery;
use SaniTube\Ui\Queries\QueueQuery;
use Tests\Support\Deployment\RemovesBackupDirectories;
use Tests\TestCase;

/**
 * The operations screens.
 *
 * Two claims carry this ticket.
 *
 * **A GET probes nothing.** This is the page somebody opens *during* an outage,
 * which is exactly when a page that reaches five external systems will not
 * load. Only an explicit "check now" may go outside.
 *
 * **No payload and no stack trace reaches the browser.** A queued job's payload
 * is a serialised object this platform does not control the contents of; a
 * failed job's exception is a stack trace naming absolute paths and the
 * application's directory layout. Neither is a field a screen may publish
 * because "we believe nothing sensitive is in there".
 */
final class SystemScreensTest extends TestCase
{
    use RefreshDatabase;
    use RemovesBackupDirectories;

    private ?User $viewer = null;

    // ------------------------------------------------------------ who may look

    #[Test]
    public function the_operations_screens_are_behind_authentication(): void
    {
        $this->get('/system/operations')->assertRedirect(route('login'));
        $this->get('/system/jobs')->assertRedirect(route('login'));
        $this->post('/system/operations/refresh')->assertRedirect(route('login'));
    }

    #[Test]
    public function a_member_may_not_see_what_the_installation_is_doing(): void
    {
        $member = $this->user(UserRole::Member);

        // A queue listing says what the installation is running and how it is
        // configured. That is administration, not catalogue work.
        $this->actingAs($member)->get('/system/operations')->assertForbidden();
        $this->actingAs($member)->get('/system/jobs')->assertForbidden();
        $this->actingAs($member)->post('/system/operations/refresh')->assertForbidden();
    }

    #[Test]
    public function an_administrator_may(): void
    {
        $admin = $this->user(UserRole::Admin);

        $this->actingAs($admin)->get('/system/operations')->assertOk();
        $this->actingAs($admin)->get('/system/jobs')->assertOk();
    }

    #[Test]
    public function the_screens_appear_in_the_navigation_only_for_administrators(): void
    {
        $navigation = $this->app->make(NavigationTree::class);

        $forOwner = array_column($navigation->for($this->user(UserRole::Owner)), 'available', 'key');
        $forMember = array_column($navigation->for($this->user(UserRole::Member, 'member@example.test')), 'available', 'key');

        $this->assertTrue($forOwner['jobs']);
        $this->assertTrue($forOwner['operations']);

        // Presentation matching the authorisation. A link somebody cannot open
        // is not honesty, it is noise.
        $this->assertFalse($forMember['jobs']);
        $this->assertFalse($forMember['operations']);
    }

    // ------------------------------------------------------------ the leak line

    #[Test]
    public function no_job_payload_reaches_the_browser(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => 'SaniTube\\Ingestion\\Jobs\\ProcessIngestionItemJob',
                'data' => ['command' => 'O:8:"stdClass":1:{s:6:"secret";s:11:"SUPER-TOKEN";}'],
            ]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time(),
        ]);

        $body = $this->actingAs($this->user())->get('/system/jobs')->assertOk()->content();

        // The class name is useful and travels. The serialised object it came
        // out of does not.
        $this->assertStringContainsString('ProcessIngestionItemJob', $body);
        $this->assertStringNotContainsString('SUPER-TOKEN', $body);
        $this->assertStringNotContainsString('stdClass', $body);
        $this->assertStringNotContainsString('payload', $body);
    }

    #[Test]
    public function no_stack_trace_reaches_the_browser(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid7(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'SaniTube\\Media\\Jobs\\AnalyzeAudioAssetJob']),
            'exception' => "RuntimeException: The provider was unreachable\n"
                ."#0 /var/www/sanitube/src/Storage/S3Provider.php(214): stream_open()\n"
                ."#1 /var/www/sanitube/vendor/laravel/framework/src/Bus/Dispatcher.php(128)\n",
            'failed_at' => now(),
        ]);

        $body = $this->actingAs($this->user())->get('/system/jobs')->assertOk()->content();

        // The first line says whether this is an outage or a bug. The trace
        // names the filesystem and the directory layout.
        $this->assertStringContainsString('The provider was unreachable', $body);
        $this->assertStringNotContainsString('/var/www/sanitube', $body);
        $this->assertStringNotContainsString('Dispatcher.php', $body);
        $this->assertStringNotContainsString('#0 ', $body);
    }

    /**
     * Truncating is not scrubbing.
     *
     * The first line used only to be shortened to two hundred characters, and
     * a limit removes the *end* of a message. An S3 client's 403 arrives as
     * `GET https://…?X-Amz-Signature=… resulted in a 403` — comfortably inside
     * the limit, and that signature is a bearer credential for as long as it
     * lives. It is in no config file, so a redactor built from configuration
     * cannot recognise it; the only rule that holds is that a failure message
     * never carries an address.
     */
    #[Test]
    public function a_signed_url_in_a_failure_message_does_not_reach_the_browser(): void
    {
        config(['filesystems.disks.r2.secret' => 'sk-live-abcdefghijklmnop']);

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid7(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'Job']),
            'exception' => 'GuzzleHttp\Exception\ClientException: GET https://bucket.r2.cloudflarestorage.com'
                .'/masters/x.wav?X-Amz-Credential=AKIAEXAMPLE&X-Amz-Signature=deadbeefcafe resulted in a 403',
            'failed_at' => now(),
        ]);

        $body = $this->actingAs($this->user())->get('/system/jobs')->assertOk()->content();

        $this->assertStringNotContainsString('X-Amz-Signature', $body);
        $this->assertStringNotContainsString('bucket.r2.cloudflarestorage.com', $body);
    }

    #[Test]
    public function a_configured_secret_in_a_failure_message_is_masked(): void
    {
        config(['filesystems.disks.r2.secret' => 'sk-live-abcdefghijklmnop']);

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid7(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'Job']),
            'exception' => 'RuntimeException: signature mismatch for key sk-live-abcdefghijklmnop',
            'failed_at' => now(),
        ]);

        $body = $this->actingAs($this->user())->get('/system/jobs')->assertOk()->content();

        $this->assertStringNotContainsString('sk-live-abcdefghijklmnop', $body);
    }

    #[Test]
    public function an_ordinary_failure_still_says_what_broke(): void
    {
        // The other half. A scrub that removed everything would pass both
        // tests above and leave an operator a screen full of blank rows —
        // which is the same as not having the screen.
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid7(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'Job']),
            'exception' => 'RuntimeException: The provider refused the request: quota exceeded',
            'failed_at' => now(),
        ]);

        $rows = $this->app->make(QueueQuery::class)->failed()['rows'];

        $this->assertStringContainsString('quota exceeded', (string) $rows[0]['error']);
    }

    #[Test]
    public function a_very_long_failure_message_is_truncated(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid7(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'Job']),
            'exception' => 'RuntimeException: '.str_repeat('x', 5000),
            'failed_at' => now(),
        ]);

        $rows = $this->app->make(QueueQuery::class)->failed()['rows'];

        // A message can be arbitrarily long, and some of them quote whatever
        // they were given.
        $this->assertLessThan(250, mb_strlen((string) $rows[0]['error']));
    }

    #[Test]
    public function a_payload_that_does_not_parse_reads_as_unrecognised_rather_than_leaking(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => 'not json at all, but it might contain SUPER-TOKEN',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time(),
        ]);

        $rows = $this->app->make(QueueQuery::class)->pending()['rows'];

        $this->assertNull($rows[0]['name']);
        $this->assertArrayNotHasKey('payload', $rows[0]);
    }

    // ------------------------------------------------------------- no probing

    #[Test]
    public function loading_operations_never_probes_anything(): void
    {
        // No snapshot stored, so if the page probed to fill the gap it would
        // reach storage and three providers right here.
        $this->app->make(OperationalHealthStore::class)->forget();

        $this->actingAs($this->user())->get('/system/operations')->assertOk();

        // And it still has not been stored, which is the assertion: reading did
        // not become writing.
        $this->assertNull($this->app->make(OperationalHealthStore::class)->current());
    }

    #[Test]
    public function an_explicit_refresh_is_what_writes_a_snapshot(): void
    {
        $store = $this->app->make(OperationalHealthStore::class);
        $store->forget();

        $this->actingAs($this->user())->post('/system/operations/refresh')->assertRedirect();

        $this->assertNotNull($store->current());
    }

    // ------------------------------------------------------------- freshness

    #[Test]
    public function nothing_stored_reads_as_never_checked_rather_than_healthy(): void
    {
        $this->app->make(OperationalHealthStore::class)->forget();

        $operations = $this->app->make(OperationsQuery::class)->get();

        $this->assertSame('UNKNOWN', $operations['health']['state']);
        $this->assertNull($operations['health']['checked_at']);
        $this->assertNull($operations['health']['storage']['healthy']);
        $this->assertNull($operations['health']['ai']['available']);
    }

    #[Test]
    public function a_stale_snapshot_never_reports_a_verdict(): void
    {
        $store = $this->app->make(OperationalHealthStore::class);

        $store->put(new OperationalHealth(
            checkedAt: (new DateTimeImmutable)->modify('-1 year'),
            storage: ['provider' => 'memory', 'healthy' => true, 'checks' => [], 'temporary_urls' => true, 'detail' => null],
            ai: ['name' => 'openai', 'available' => true],
            generation: ['name' => 'fake', 'available' => true],
            distributors: [['name' => 'fake', 'available' => true]],
            capabilities: ['healthy' => true, 'items' => []],
        ));

        $health = $this->app->make(OperationsQuery::class)->get()['health'];

        $this->assertSame('STALE', $health['state']);

        // Every verdict withheld. A result from a year ago is not evidence
        // about now, and a screen reporting green through an outage is worse
        // than one reporting nothing.
        $this->assertNull($health['storage']['healthy']);
        $this->assertNull($health['ai']['available']);
        $this->assertNull($health['generation']['available']);
        $this->assertNull($health['distributors'][0]['available']);
        $this->assertNull($health['capabilities']['healthy']);

        // The names and the timestamp survive: they say *what* was checked.
        $this->assertSame('memory', $health['storage']['provider']);
        $this->assertNotNull($health['checked_at']);
    }

    #[Test]
    public function a_fresh_snapshot_passes_its_verdicts_through(): void
    {
        $this->app->make(OperationalHealthStore::class)->put(new OperationalHealth(
            checkedAt: new DateTimeImmutable,
            storage: ['provider' => 'memory', 'healthy' => true, 'checks' => [], 'temporary_urls' => true, 'detail' => null],
            ai: ['name' => 'none', 'available' => false],
            generation: ['name' => 'fake', 'available' => true],
            distributors: [],
            capabilities: ['healthy' => true, 'items' => []],
        ));

        $health = $this->app->make(OperationsQuery::class)->get()['health'];

        $this->assertSame('FRESH', $health['state']);
        $this->assertTrue($health['storage']['healthy']);
        $this->assertFalse($health['ai']['available']);
    }

    // ------------------------------------------------------------- scheduler

    #[Test]
    public function a_scheduler_that_never_ran_says_so_rather_than_reading_as_recent(): void
    {
        $operations = $this->app->make(OperationsQuery::class)->get();

        // A cron entry nobody created is one of the quietest production
        // failures there is. Null, not a timestamp, and not zero seconds ago.
        $this->assertNull($operations['scheduler']['last_run_at']);
        $this->assertNull($operations['scheduler']['seconds_since']);
    }

    #[Test]
    public function a_recorded_heartbeat_is_reported(): void
    {
        $this->app->make(SchedulerHeartbeat::class)->record();

        $operations = $this->app->make(OperationsQuery::class)->get();

        $this->assertNotNull($operations['scheduler']['last_run_at']);
        $this->assertLessThan(60, (int) $operations['scheduler']['seconds_since']);
    }

    // ----------------------------------------------------------------- queue

    #[Test]
    public function queue_counts_are_real_and_scoped(): void
    {
        DB::table('jobs')->insert([
            ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()],
            ['queue' => 'default', 'payload' => '{}', 'attempts' => 1, 'reserved_at' => time(), 'available_at' => time(), 'created_at' => time()],
        ]);

        $summary = $this->app->make(QueueQuery::class)->summary();

        $this->assertSame(2, $summary['pending']);
        $this->assertSame(1, $summary['reserved']);
        $this->assertSame(0, $summary['failed']);
        $this->assertTrue($summary['readable']);
    }

    #[Test]
    public function an_installation_that_has_never_been_backed_up_says_so(): void
    {
        config(['backup.destination' => storage_path('framework/testing/backups-never')]);

        $backups = $this->app->make(OperationsQuery::class)->get()['backups'];

        // Never backed up and backed up a long time ago are different
        // problems. A dash for both is how the first goes unnoticed for
        // months, which is the classic disaster this panel exists for.
        $this->assertTrue($backups['readable']);
        $this->assertNull($backups['taken_at']);
        $this->assertSame(0, $backups['count']);
    }

    #[Test]
    public function a_backup_destination_inside_the_web_root_reports_itself_rather_than_breaking_the_screen(): void
    {
        config(['backup.destination' => public_path('backups')]);

        $backups = $this->app->make(OperationsQuery::class)->get()['backups'];

        // This is the page somebody opens when things are already wrong. A
        // misconfiguration must appear on it, not take it down.
        $this->assertFalse($backups['readable']);
        $this->assertNull($backups['count']);

        $this->actingAs($this->user())->get('/system/operations')->assertOk();
    }

    #[Test]
    public function loading_operations_never_verifies_a_backup(): void
    {
        config(['backup.destination' => storage_path('framework/testing/backups-corrupt')]);

        $result = $this->app->make(CreateBackup::class)->handle();
        file_put_contents($result['path'].'/database.jsonl', 'tampered', FILE_APPEND);

        try {
            $backups = $this->app->make(OperationsQuery::class)->get()['backups'];

            // Verifying reads every byte of every backup. That is
            // `sanitube:restore --verify`, and it has no business running
            // during a page load — the listing reports what exists, not
            // whether it is intact.
            $this->assertSame(1, $backups['count']);
            $this->assertNotNull($backups['taken_at']);
        } finally {
            $this->rmrf(storage_path('framework/testing/backups-corrupt'));
        }
    }

    #[Test]
    public function an_unreadable_queue_reports_unknown_rather_than_zero(): void
    {
        // What an installation running Redis or SQS looks like: no database
        // tables to count. Rendering 0 would say "nothing is waiting".
        DB::statement('DROP TABLE IF EXISTS jobs');
        DB::statement('DROP TABLE IF EXISTS failed_jobs');

        $summary = $this->app->make(QueueQuery::class)->summary();

        $this->assertFalse($summary['readable']);
        $this->assertNull($summary['pending']);
        $this->assertNull($summary['reserved']);
        $this->assertNull($summary['failed']);

        // And the page still renders.
        $this->actingAs($this->user())->get('/system/jobs')->assertOk();
    }

    // -------------------------------------------------------------- fixtures

    private function user(UserRole $role = UserRole::Owner, string $email = 'owner@example.test'): User
    {
        if ($role === UserRole::Owner && $this->viewer instanceof User) {
            return $this->viewer;
        }

        $user = User::query()->create([
            'name' => 'Operator',
            'email' => $role === UserRole::Owner ? $email : strtolower($role->value).'@example.test',
            'password' => 'a-long-enough-passphrase',
        ]);

        $user->forceFill(['role' => $role, 'is_active' => true])->save();
        $user->refresh();

        if ($role === UserRole::Owner) {
            $this->viewer = $user;
        }

        return $user;
    }
}
