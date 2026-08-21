<?php

declare(strict_types=1);

namespace Tests\Feature\Deployment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Deployment\Exceptions\BackupException;
use SaniTube\Deployment\Services\BackupRepository;
use SaniTube\Deployment\Services\CreateBackup;
use SaniTube\Deployment\Services\RestoreBackup;
use Tests\Support\Deployment\RemovesBackupDirectories;
use Tests\TestCase;

/**
 * What a backup is told to include, and what it takes.
 *
 * `backup.destination` was checked on every call and `backup.include_paths`
 * was not checked at all: it was resolved against the application root and
 * walked. The destination is the setting somebody worries about; the include
 * list is the one they edit.
 *
 * Four claims, each of which was false before this file existed:
 *
 *   - **Nothing outside the application is copied.** `../` is a path, and
 *     `base_path('../')` is a directory. On shared hosting it is somebody
 *     else's account.
 *   - **A backup never contains a backup.** The default destination lives
 *     inside `storage/`, so `storage` — the obvious entry for "back up my
 *     storage directory" — used to copy every previous backup into the new
 *     one. That doubles on each run, silently, until a nightly job has been
 *     failing on disk space for a week.
 *   - **`.env` is never in a backup, and never restored from one.** Both
 *     directions, from one predicate, because a rule that only held while
 *     writing is a rule a backup from last year walks past.
 *   - **A path that produced nothing says so.** The manifest exists to be
 *     read six months later by somebody deciding whether they can restore.
 *
 * The refusals happen *before* the backup directory is created. A
 * configuration mistake found afterwards leaves an operator a half-written
 * directory to clean up on top of the mistake to fix.
 */
final class BackupContentsTest extends TestCase
{
    use RefreshDatabase;
    use RemovesBackupDirectories;

    private string $destination;

    /** @var list<string> */
    private array $litter = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->destination = storage_path('framework/testing/backups-'.bin2hex(random_bytes(4)));
        config(['backup.destination' => $this->destination]);
    }

    protected function tearDown(): void
    {
        foreach ($this->litter as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->rmrf($this->destination);

        parent::tearDown();
    }

    // ------------------------------------------------------ what is refused

    #[Test]
    public function a_path_that_leaves_the_application_is_refused(): void
    {
        config(['backup.include_paths' => ['..']]);

        $this->expectException(BackupException::class);

        try {
            $this->create()->handle();
        } catch (BackupException $exception) {
            $this->assertSame('INCLUDE_PATH_ESCAPES', $exception->reason);

            throw $exception;
        }
    }

    #[Test]
    public function a_path_that_climbs_and_comes_back_is_still_refused(): void
    {
        // The interesting shape: it resolves outside and then in again, so a
        // check on the configured string rather than on the resolved path
        // would let it through.
        config(['backup.include_paths' => ['storage/../../']]);

        $this->expectException(BackupException::class);

        $this->create()->handle();
    }

    #[Test]
    public function the_application_root_is_not_an_include_path(): void
    {
        // The destination is moved *outside* the installation first, and that
        // is the whole design of this test. With the default destination the
        // root contains it, so the destination rule refuses `.` on its own —
        // and a version of this file that accepted either refusal passed with
        // the root rule deleted. Put the destination somewhere the root does
        // not contain and only one rule is left to do the work.
        $this->destination = sys_get_temp_dir().'/sanitube-backups-'.bin2hex(random_bytes(4));
        config(['backup.destination' => $this->destination, 'backup.include_paths' => ['.']]);

        try {
            $this->create()->handle();
            $this->fail('The whole installation was accepted as something to include.');
        } catch (BackupException $exception) {
            $this->assertSame(
                'INCLUDE_PATH_IS_APPLICATION',
                $exception->reason,
                'The root was walked — vendor, node_modules and .git — rather than refused.',
            );
        }
    }

    #[Test]
    public function a_path_containing_the_backup_destination_is_refused(): void
    {
        // The same relationship the shipped configuration has — the default
        // destination is inside `storage/`, and `storage` is what an operator
        // writes when they mean "everything in my storage directory" —
        // arranged inside this test's own directory rather than pointed at the
        // real one. A test that aimed at `storage/backups` would, on the day
        // the guard broke, write a real backup into a real backup directory
        // and leave it there.
        config(['backup.include_paths' => ['storage/framework/testing']]);

        try {
            $this->create()->handle();
            $this->fail('A backup was told to copy the directory it writes into.');
        } catch (BackupException $exception) {
            $this->assertSame('INCLUDE_PATH_IS_DESTINATION', $exception->reason);
        }
    }

    #[Test]
    public function nothing_is_written_when_an_include_path_is_refused(): void
    {
        config(['backup.include_paths' => ['..']]);

        try {
            $this->create()->handle();
        } catch (BackupException) {
            // Expected.
        }

        // `scandir`, minus the two entries every directory has. A glob here
        // would call a directory holding nothing but a dotfile empty, which is
        // the same blind spot this whole file is about.
        $left = is_dir($this->destination)
            ? array_values(array_diff(scandir($this->destination) ?: [], ['.', '..']))
            : [];

        $this->assertSame([], $left, 'A refused configuration still left a directory behind.');
    }

    // -------------------------------------------------------- what is taken

    #[Test]
    public function an_environment_file_inside_an_included_directory_is_not_copied(): void
    {
        $root = $this->includable();
        file_put_contents($root.'/.env', 'APP_KEY=base64:not-a-real-key');
        file_put_contents($root.'/.env.production', 'DB_PASSWORD=hunter2');
        file_put_contents($root.'/cover.jpg', 'jpeg');

        $manifest = $this->create()->handle()['manifest'];

        $paths = array_column($manifest->entries, 'path');

        $this->assertNotEmpty(
            array_filter($paths, static fn (string $path): bool => str_ends_with($path, 'cover.jpg')),
            'The directory was not copied at all, so this proves nothing about what was left out.',
        );

        foreach ($paths as $path) {
            $this->assertStringNotContainsString(
                '.env',
                $path,
                'An environment file was copied into a backup. It holds every credential this installation has.',
            );
        }
    }

    #[Test]
    public function every_manifest_says_that_environment_files_are_never_included(): void
    {
        $manifest = $this->create()->handle()['manifest'];

        $this->assertArrayHasKey(
            'files:environment',
            $manifest->excluded,
            'A manifest has to be readable as a list of what is *not* in the backup, not only what is.',
        );
    }

    #[Test]
    public function an_ordinary_hidden_file_is_still_copied(): void
    {
        // The rule is `.env`, not "anything with a dot". The previous
        // implementation used `glob()`, which silently skipped every hidden
        // file — `.env` was left out by accident rather than by decision, and
        // an accident does not survive somebody switching to a different
        // directory walk.
        $root = $this->includable();
        file_put_contents($root.'/.hidden-but-ordinary', 'kept');

        $manifest = $this->create()->handle()['manifest'];

        $this->assertNotEmpty(
            array_filter(
                array_column($manifest->entries, 'path'),
                static fn (string $path): bool => str_ends_with($path, '.hidden-but-ordinary'),
            ),
            'Hidden files are dropped wholesale, so nothing here proves .env is refused on purpose.',
        );
    }

    #[Test]
    public function a_configured_path_that_is_not_there_is_named_in_the_manifest(): void
    {
        config(['backup.include_paths' => ['storage/app/public', 'storage/app/nothing-here']]);

        $manifest = $this->create()->handle()['manifest'];

        $this->assertArrayHasKey(
            'path:storage/app/nothing-here',
            $manifest->excluded,
            'A path in the configuration and nothing on disk is an operator who believes something is backed up.',
        );
    }

    #[Test]
    public function pruning_removes_a_backup_that_contains_a_hidden_file(): void
    {
        // The other half of taking hidden files seriously. Pruning is the only
        // routine, automatic, destructive act in the platform, and it ran on a
        // glob — so the first backup containing a dotfile would have been
        // emptied of everything the glob could see and then left standing,
        // for ever, because `rmdir` refuses a directory that is not empty.
        $root = $this->includable();
        file_put_contents($root.'/.hidden-but-ordinary', 'kept');

        $oldest = $this->create()->handle()['path'];
        $this->create()->handle();

        $removed = $this->app->make(BackupRepository::class)->prune(1);

        $this->assertSame([$oldest], $removed);
        $this->assertDirectoryDoesNotExist($oldest, 'Pruning reported a removal it did not make.');
    }

    // ------------------------------------------------------- the way back in

    #[Test]
    public function a_backup_naming_an_environment_file_is_refused_on_restore(): void
    {
        $result = $this->create()->handle();

        // What a backup taken before this rule existed looks like — and what a
        // tampered one looks like too, checksum and all, because whoever
        // edited the files edited the manifest beside them.
        $planted = $this->plant($result['path']);

        $this->rewriteManifest($result['path'], [
            'entries' => [
                ...$result['manifest']->entries,
                [
                    'path' => 'files/storage/app/public/.env',
                    'bytes' => filesize($planted),
                    'sha256' => hash_file('sha256', $planted),
                ],
            ],
        ]);

        try {
            $this->restore()->verify($result['path']);
            $this->fail('A backup carrying an environment file was accepted for restore.');
        } catch (BackupException $exception) {
            $this->assertSame('ENVIRONMENT_FILE_IN_BACKUP', $exception->reason);
            $this->assertStringContainsString('.env', $exception->getMessage());
        }
    }

    #[Test]
    public function the_refusal_happens_before_the_live_environment_is_touched(): void
    {
        $result = $this->create()->handle();

        $planted = $this->plant($result['path']);

        $this->rewriteManifest($result['path'], [
            'entries' => [
                ...$result['manifest']->entries,
                [
                    'path' => 'files/storage/app/public/.env',
                    'bytes' => filesize($planted),
                    'sha256' => hash_file('sha256', $planted),
                ],
            ],
        ]);

        $landing = base_path('storage/app/public/.env');
        $this->litter[] = $landing;

        $liveBefore = is_file(base_path('.env')) ? (string) file_get_contents(base_path('.env')) : null;

        try {
            $this->restore()->handle($result['path'], confirmed: true, allowSchemaDrift: true);
        } catch (BackupException) {
            // Expected.
        }

        $this->assertFileDoesNotExist($landing, 'An environment file from a backup was written into the application.');

        $liveAfter = is_file(base_path('.env')) ? (string) file_get_contents(base_path('.env')) : null;

        $this->assertSame(
            $liveBefore,
            $liveAfter,
            "The live installation's environment file was overwritten by a restore.",
        );
    }

    // -------------------------------------------------------------- fixtures

    private function create(): CreateBackup
    {
        return $this->app->make(CreateBackup::class);
    }

    private function restore(): RestoreBackup
    {
        return $this->app->make(RestoreBackup::class);
    }

    /**
     * A real, contained directory to include, and cleaned up afterwards.
     *
     * `storage/app/public` is what the shipped configuration includes, so
     * writing here tests the path an installation actually takes rather than
     * one arranged to pass.
     */
    private function includable(): string
    {
        $root = storage_path('app/public');

        if (! is_dir($root)) {
            mkdir($root, 0o750, true);
        }

        // Only names this test invents. `storage/app/public/.gitignore` is a
        // tracked file, and an earlier version of this list deleted it — a
        // test that tidies away part of the repository is worse than one that
        // leaves a stray file behind.
        foreach (['.env', '.env.production', '.hidden-but-ordinary', 'cover.jpg'] as $name) {
            $this->litter[] = $root.'/'.$name;
        }

        return $root;
    }

    /**
     * An environment file inside an existing backup, as an older or an edited
     * one would carry it.
     *
     * Planted at `files/storage/app/public/.env` rather than `files/.env`, and
     * that choice is not cosmetic. A restore turns the entry into a path under
     * the application root, so an entry of `files/.env` writes the live
     * `.env` — and the first version of this file, run against a build with
     * the guard removed, overwrote the working installation's `.env` with
     * `DB_PASSWORD=hunter2` while proving that it must not. A test whose
     * failure destroys the thing it is protecting is a test that can only be
     * run once.
     *
     * Under `storage/app/public` the blast radius is a file this class already
     * cleans up, and the entry is still an environment file — at a depth, which
     * is the harder half of the rule anyway.
     *
     * @return string the planted path
     */
    private function plant(string $directory): string
    {
        $planted = $directory.'/files/storage/app/public/.env';

        if (! is_dir(dirname($planted))) {
            mkdir(dirname($planted), 0o750, true);
        }

        file_put_contents($planted, 'DB_PASSWORD=hunter2');

        return $planted;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function rewriteManifest(string $directory, array $changes): void
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($directory.'/manifest.json'), true);

        file_put_contents($directory.'/manifest.json', (string) json_encode(array_merge($decoded, $changes)));
    }
}
