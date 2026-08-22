<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Deployment\Frontend\FrontendBuildInstaller;
use SaniTube\Deployment\Services\DiagnoseProduction;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Ui\Queries\SystemAboutQuery;
use Tests\TestCase;

/**
 * What this installation is, and whether it is well.
 *
 * SYS-001. `sanitube:doctor` has reported nineteen things worth knowing about
 * a live installation since DEP-016 — a queue running inline, a directory that
 * is not writable, an upload ceiling the host will not honour — to a terminal.
 * The operator most likely to need it is the one on shared hosting who chose
 * this platform *because* they did not want a shell, and they could not read a
 * word of it.
 *
 * The identity half was worse than missing: it was never recorded. `app.version`
 * was read by the backup manifest and set by nothing, so every backup this
 * platform has written says `unknown`.
 *
 * These tests carry four claims.
 *
 *   - **Nothing is invented.** A version this platform made up would be worse
 *     than none: the question is "am I on the release that fixed the thing",
 *     and a hardcoded string answers yes for ever.
 *   - **`UNKNOWN` is never a pass.** A check that could not be made is not a
 *     check that succeeded, and reporting one as the other is how a screen
 *     reassures somebody about a server that is already down.
 *   - **Pending migrations are named, not counted.** The names say what
 *     changed; a number only says there is a problem.
 *   - **Nothing on the page is a credential**, and nothing on it writes.
 */
final class SystemAboutTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $gitFixtures = [];

    protected function tearDown(): void
    {
        foreach ($this->gitFixtures as $root) {
            $this->removeTree($root);
        }

        $this->gitFixtures = [];

        parent::tearDown();
    }

    private function removeTree(string $path): void
    {
        foreach ((array) glob($path.'/*') as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            is_dir($entry) ? $this->removeTree($entry) : @unlink($entry);
        }

        @rmdir($path);
    }

    // ------------------------------------------------------------- identity

    #[Test]
    public function an_unrecorded_version_is_null_and_never_a_guess(): void
    {
        config(['app.version' => null]);

        $this->assertNull($this->about()['installation']['version']);
    }

    #[Test]
    public function the_version_the_deployment_recorded_is_the_one_reported(): void
    {
        config(['app.version' => '1.4.2']);

        $this->assertSame('1.4.2', $this->about()['installation']['version']);
    }

    #[Test]
    public function a_blank_version_is_the_same_as_none(): void
    {
        // An env var set to nothing is what a deploy script leaves behind when
        // its own version lookup failed. Reporting an empty string would put a
        // blank where a sentence explaining the blank belongs.
        config(['app.version' => '   ']);

        $this->assertNull($this->about()['installation']['version']);
    }

    #[Test]
    public function the_commit_is_read_from_files_and_never_from_a_shell(): void
    {
        // Pointed at a fixture rather than at this repository's own `.git`, so
        // the assertion is about the reading and not about how the test runner
        // happened to obtain the code. Three files and no process: shelling
        // out to `git` would mean executing a binary to render a page, on a
        // host that may not have it, from a request anybody with an
        // administrator session can make.
        $commit = str_repeat('ab12cd34', 5);

        $this->pointAtGitDirectory(['HEAD' => 'ref: refs/heads/main', 'refs/heads/main' => $commit]);

        $this->assertSame($commit, $this->about()['installation']['commit']);
    }

    #[Test]
    public function a_detached_head_is_read_just_as_well(): void
    {
        $commit = str_repeat('ff00ee11', 5);

        $this->pointAtGitDirectory(['HEAD' => $commit]);

        $this->assertSame($commit, $this->about()['installation']['commit']);
    }

    #[Test]
    public function a_deployment_with_no_history_reports_none_rather_than_failing(): void
    {
        // A release tarball or a cPanel upload. Not a failure: a deployment
        // that does not carry its own history, and the screen says so.
        $this->pointAtGitDirectory([]);

        $this->assertNull($this->about()['installation']['commit']);
    }

    #[Test]
    public function the_version_comes_from_the_environment_rather_than_from_a_constant(): void
    {
        // The config expression itself, re-read with the variable set. Every
        // other test here sets `app.version` directly and would pass against a
        // config file that had stopped reading the environment at all — which
        // is the state where an operator sets the variable and the screen
        // never changes.
        //
        // Written into the repository `env()` reads rather than through
        // `putenv`. `.env.example` documents `SANITUBE_VERSION=` as an empty
        // line, so on any installation whose `.env` came from it the name is
        // already defined — and Dotenv keeps the value it loaded, which made
        // this pass locally and fail everywhere the file had been copied.
        $repository = Env::getRepository();
        $before = $repository->get('SANITUBE_VERSION');

        $repository->set('SANITUBE_VERSION', '9.9.9-fixture');

        try {
            /** @var array<string, mixed> $config */
            $config = require base_path('config/app.php');

            $this->assertSame('9.9.9-fixture', $config['version']);
        } finally {
            $before === null
                ? $repository->clear('SANITUBE_VERSION')
                : $repository->set('SANITUBE_VERSION', $before);
        }
    }

    #[Test]
    public function the_debug_flag_is_published_because_being_wrong_about_it_is_invisible(): void
    {
        config(['app.debug' => true]);

        $this->assertTrue($this->about()['installation']['debug']);
    }

    // ------------------------------------------------------------- the schema

    #[Test]
    public function it_says_how_many_migrations_have_run_and_which_was_last(): void
    {
        $migrations = $this->about()['migrations'];

        $this->assertTrue($migrations['measured']);
        $this->assertGreaterThan(0, $migrations['applied']);

        // The *most recent*, which with date-prefixed names is the greatest.
        // "The first migration ever written" is a fact about the project's
        // history rather than about this installation's schema.
        /** @var list<string> $applied */
        $applied = $this->app->make('db')->connection()->table('migrations')->pluck('migration')->all();
        sort($applied);

        $this->assertSame($applied[count($applied) - 1], $migrations['latest']);
        $this->assertNotSame($applied[0], $migrations['latest']);
    }

    #[Test]
    public function a_fully_migrated_installation_has_nothing_pending(): void
    {
        // The suite migrates before it runs, so anything pending here would be
        // a migration this code carries and never applies — which is exactly
        // the state this panel exists to catch.
        $this->assertSame([], $this->about()['migrations']['pending']);
    }

    #[Test]
    public function a_migration_this_code_carries_and_has_not_run_is_named(): void
    {
        // Named rather than counted. "Three pending" tells an operator there
        // is a problem; the names tell them what changed.
        $applied = $this->app->make('db')->connection()->table('migrations');
        $removed = (string) $applied->orderByDesc('migration')->value('migration');

        $applied->where('migration', $removed)->delete();

        $pending = $this->about()['migrations']['pending'];

        $this->assertContains($removed, $pending);
        $this->assertCount(1, $pending);
    }

    #[Test]
    public function an_unreadable_schema_says_so_rather_than_reporting_zero(): void
    {
        $migrations = $this->onABlankDatabase()['migrations'];

        $this->assertFalse($migrations['measured']);
        $this->assertNull($migrations['applied']);
        $this->assertNull($migrations['pending']);
    }

    // ----------------------------------------------------------- the doctor

    #[Test]
    public function the_doctor_reaches_a_screen(): void
    {
        $diagnosis = $this->about()['diagnosis'];

        $this->assertTrue($diagnosis['measured']);
        $this->assertNotSame([], $diagnosis['checks']);

        foreach ($diagnosis['checks'] as $check) {
            $this->assertContains($check['verdict'], ['READY', 'WARNING', 'BLOCKER', 'UNKNOWN']);
            $this->assertNotSame('', $check['summary']);
        }
    }

    #[Test]
    public function an_unknown_is_carried_through_as_itself(): void
    {
        // A check that could not be made is not a check that succeeded, and
        // folding one into the other is how a screen reassures somebody about
        // a server that is already down.
        $counts = $this->about()['diagnosis']['counts'];

        foreach (['READY', 'WARNING', 'BLOCKER', 'UNKNOWN'] as $verdict) {
            $this->assertArrayHasKey($verdict, $counts, $verdict.' is not counted at all.');
        }

        $this->assertSame(
            count($this->about()['diagnosis']['checks']),
            array_sum($counts),
            'The counts do not add up to the checks they came from.',
        );
    }

    #[Test]
    public function a_check_that_could_not_be_made_is_reported_as_unknown(): void
    {
        // A database with no schema at all makes the migration check
        // unanswerable, which is the honest shape of an UNKNOWN: not a
        // failure, not a pass, a question with no answer. The doctor is
        // explicit that this must not become a blocker about migrations —
        // that would be inventing a finding from a question that never
        // completed.
        $diagnosis = $this->onABlankDatabase()['diagnosis'];

        $unknown = array_values(array_filter(
            $diagnosis['checks'],
            static fn (array $check): bool => $check['verdict'] === 'UNKNOWN',
        ));

        $this->assertNotSame([], $unknown, 'Nothing was reported as unknown, so nothing below is tested.');

        // Counted as itself. Folding an unknown into the ready column is how a
        // screen reassures somebody about a server that is already down.
        $this->assertSame(count($unknown), $diagnosis['counts']['UNKNOWN']);
    }

    #[Test]
    public function a_blocking_finding_is_reported_as_blocking(): void
    {
        // The queue on `sync` is a blocker the doctor has always found, and it
        // is the shape of finding this screen exists for: importing nine
        // hundred files inside one web request will time out.
        config(['queue.default' => 'sync']);

        $blocking = array_values(array_filter(
            $this->about()['diagnosis']['checks'],
            static fn (array $check): bool => $check['verdict'] === 'BLOCKER',
        ));

        $this->assertNotSame([], $blocking);
    }

    // ---------------------------------------------------------- the screen

    #[Test]
    public function an_administrator_reads_it(): void
    {
        $about = $this->actingAs($this->administrator())
            ->get('/system/about')
            ->viewData('page')['props']['about'];

        $this->assertArrayHasKey('installation', $about);
        $this->assertArrayHasKey('diagnosis', $about);
    }

    #[Test]
    public function the_screen_publishes_no_credential(): void
    {
        // The doctor reads provider configuration to decide what it can say
        // about it. What reaches the browser has to be the verdict and never
        // the value.
        config([
            'ai.providers.openai.key' => 'sk-a-key-that-must-not-travel',
            'worker.token' => 'a-worker-token-that-must-not-travel',
            'mail.mailers.smtp.password' => 'a-mail-password-that-must-not-travel',
        ]);

        $body = (string) $this->actingAs($this->administrator())->get('/system/about')->getContent();

        foreach (['sk-a-key-that-must-not-travel', 'a-worker-token-that-must-not-travel', 'a-mail-password-that-must-not-travel'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    #[Test]
    public function a_member_cannot_read_it(): void
    {
        $member = User::factory()->create(['role' => UserRole::Member, 'is_active' => true]);

        $this->actingAs($member)->get('/system/about')->assertForbidden();
    }

    #[Test]
    public function a_stranger_is_sent_to_sign_in(): void
    {
        // Its own test rather than a second call in the one above: `actingAs`
        // persists for the rest of the case, so an unauthenticated assertion
        // after an authenticated one is asserting about the wrong request.
        $this->get('/system/about')->assertRedirect('/login');
    }

    // ---------------------------------------------------------- the fixtures

    /**
     * @return array<string, mixed>
     */
    private function about(): array
    {
        return $this->app->make(SystemAboutQuery::class)->overview();
    }

    /**
     * The same reading, taken against a database that holds nothing.
     *
     * **Deliberately not `Schema::drop`.** DDL does not roll back on MySQL or
     * MariaDB, so dropping a table inside a test is a change the transaction
     * cannot undo — and dropping `migrations` in particular makes the next
     * class believe the database was never migrated, which fails on `users`
     * already existing. It passed on SQLite, where DDL *is* transactional,
     * and took four database jobs down.
     *
     * Pointing the default connection at an empty in-memory database asks the
     * same question and destroys nothing.
     *
     * @return array<string, mixed>
     */
    private function onABlankDatabase(): array
    {
        config(['database.connections.blank' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);

        $was = (string) config('database.default');
        config(['database.default' => 'blank']);

        // Resolved after the switch, so it is handed the blank connection
        // rather than the one it would have been given at boot.
        $this->app->forgetInstance(SystemAboutQuery::class);
        $this->app->forgetInstance(DiagnoseProduction::class);
        DB::purge('blank');

        try {
            return $this->app->make(SystemAboutQuery::class)->overview();
        } finally {
            config(['database.default' => $was]);
            $this->app->forgetInstance(SystemAboutQuery::class);
            $this->app->forgetInstance(DiagnoseProduction::class);
        }
    }

    /**
     * Point the commit reader at a fixture directory instead of this repo.
     *
     * @param  array<string, string>  $files  relative path => contents
     */
    private function pointAtGitDirectory(array $files): void
    {
        $root = sys_get_temp_dir().'/sanitube-git-'.bin2hex(random_bytes(6));
        mkdir($root, 0755, true);
        $this->gitFixtures[] = $root;

        foreach ($files as $relative => $contents) {
            $path = $root.'/'.$relative;
            $directory = dirname($path);

            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            file_put_contents($path, $contents."\n");
        }

        $this->app->bind(
            FrontendBuildInstaller::class,
            fn (): FrontendBuildInstaller => new FrontendBuildInstaller(
                publicPath: public_path('build'),
                statePath: storage_path('framework'),
                gitPath: $root,
            ),
        );
    }

    private function administrator(): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    }
}
