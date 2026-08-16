<?php

declare(strict_types=1);

namespace Tests\Feature\Deployment;

use App\Models\User;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SaniTube\Deployment\BackupManifest;
use SaniTube\Deployment\Exceptions\BackupException;
use SaniTube\Deployment\Services\BackupRepository;
use SaniTube\Deployment\Services\CreateBackup;
use SaniTube\Deployment\Services\DatabaseDumper;
use SaniTube\Deployment\Services\RestoreBackup;
use SaniTube\Distribution\Enums\DistributionAction;
use SaniTube\Distribution\Enums\DistributionAttemptOutcome;
use SaniTube\Distribution\Enums\DistributionDeliveryStatus;
use SaniTube\Distribution\Models\DistributionAttempt;
use SaniTube\Distribution\Models\DistributionDelivery;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Localization\ContentLanguage;
use SaniTube\Releases\Enums\ReleaseStatus;
use SaniTube\Releases\Enums\ReleaseType;
use SaniTube\Releases\Models\Release;
use Tests\TestCase;

/**
 * Backup and restore.
 *
 * A restore is the single most destructive operation in the platform — the
 * only one that deliberately overwrites data somebody is still using — so most
 * of these tests are about the ways it must refuse. The happy path is two
 * tests; the refusals are a dozen, and that ratio is the point.
 *
 * The claims:
 *
 *   - **A partial backup never looks complete.** The manifest is written last,
 *     and nothing without one is offered for restore or pruned.
 *   - **A corrupt backup is refused before anything is written.** Restoring
 *     damage over working data is worse than not restoring.
 *   - **Schema drift is refused by default.** Rows from an older schema in a
 *     newer database is how a catalogue is corrupted quietly.
 *   - **A restore is never accidental.** Not a flag that another flag implies.
 *   - **Nothing escapes the backup directory** — not on read, not on write,
 *     and not while pruning.
 */
final class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $destination;

    protected function setUp(): void
    {
        parent::setUp();

        $this->destination = storage_path('framework/testing/backups-'.bin2hex(random_bytes(4)));
        config(['backup.destination' => $this->destination]);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->destination);

        parent::tearDown();
    }

    // ------------------------------------------------------------- taking one

    #[Test]
    public function a_backup_captures_the_rows_that_are_in_the_database(): void
    {
        $this->user('owner@example.test');

        $result = $this->create()->handle();

        $dump = (string) file_get_contents($result['path'].'/database.jsonl');

        $this->assertStringContainsString('"_table":"users"', $dump);
        $this->assertStringContainsString('owner@example.test', $dump);
    }

    #[Test]
    public function every_entry_carries_its_own_checksum(): void
    {
        $result = $this->create()->handle();

        $this->assertNotSame([], $result['manifest']->entries);

        foreach ($result['manifest']->entries as $entry) {
            // Per entry, not one digest over the whole archive: a restore that
            // can name the damaged file is one somebody can act on.
            $this->assertSame(64, strlen($entry['sha256']), $entry['path'].' has no usable checksum.');
            $this->assertSame(
                $entry['sha256'],
                hash_file('sha256', $result['path'].'/'.$entry['path']),
            );
        }
    }

    #[Test]
    public function the_manifest_records_what_was_left_out(): void
    {
        $manifest = $this->create()->handle()['manifest'];

        // A backup that quietly omits something is a backup nobody can reason
        // about, and the omission is found at exactly the wrong moment.
        $this->assertArrayHasKey('files:audio-masters', $manifest->excluded);
        $this->assertArrayHasKey('table:cache', $manifest->excluded);

        foreach ($manifest->excluded as $what => $why) {
            $this->assertGreaterThan(30, strlen($why), $what.' is excluded without a stated reason.');
        }
    }

    #[Test]
    public function ephemeral_tables_are_structurally_present_and_empty(): void
    {
        $this->app->make('db')->connection()->table('cache')->insert([
            'key' => 'k', 'value' => 'v', 'expiration' => time() + 60,
        ]);

        $dump = (string) file_get_contents($this->create()->handle()['path'].'/database.jsonl');

        // "This table was backed up and was empty" and "this table was not
        // backed up" are different facts, and a restore has to tell them apart.
        $this->assertStringContainsString('"_table":"cache","_skipped":true', $dump);
        $this->assertStringNotContainsString('"_row":{"key":"k"', $dump);
    }

    #[Test]
    public function the_migration_set_is_recorded_so_drift_can_be_detected(): void
    {
        $manifest = $this->create()->handle()['manifest'];

        $this->assertNotSame([], $manifest->migrations);
    }

    // ------------------------------------------------------- where it is put

    #[Test]
    public function a_destination_inside_the_web_root_is_refused(): void
    {
        config(['backup.destination' => public_path('backups')]);

        // A backup holds every row of the catalogue. One served over HTTP is
        // worse than no backup at all.
        $this->expectException(BackupException::class);

        try {
            $this->repository()->destination();
        } catch (BackupException $exception) {
            $this->assertSame('DESTINATION_IS_PUBLIC', $exception->reason);

            throw $exception;
        }
    }

    #[Test]
    public function the_web_root_itself_is_refused_too(): void
    {
        config(['backup.destination' => public_path()]);

        $this->expectException(BackupException::class);

        $this->repository()->destination();
    }

    // --------------------------------------------------------- completeness

    #[Test]
    public function a_directory_without_a_manifest_is_not_a_backup(): void
    {
        $orphan = $this->destination.'/2020-01-01_000000';
        mkdir($orphan, 0o750, true);
        file_put_contents($orphan.'/database.jsonl', "{}\n");

        // A run interrupted halfway leaves exactly this. It must not be
        // offered for restore, because restoring part of a backup is not
        // restoring.
        $this->assertSame([], $this->repository()->complete());
    }

    #[Test]
    public function a_backup_that_died_halfway_is_not_offered_for_restore(): void
    {
        // The whole crash-safety story: the manifest is written last, so there
        // is no moment at which a partial backup looks complete. Proving it
        // needs an actual failure mid-run, not an inspection of the order.
        $this->app->bind(DatabaseDumper::class, fn (): DatabaseDumper => new class extends DatabaseDumper
        {
            public function __construct() {}

            public function tables(): array
            {
                return [];
            }

            public function lines(array $skipContentsOf = []): Generator
            {
                yield '{"_table":"users","_skipped":false}';

                throw new RuntimeException('The disk filled up.');
            }
        });

        try {
            $this->app->make(CreateBackup::class)->handle();
            $this->fail('The backup reported success after the dump failed.');
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertSame([], $this->repository()->complete());
        $this->assertNotSame([], glob($this->destination.'/*') ?: [], 'The failed run left nothing to find.');
    }

    #[Test]
    public function a_backup_missing_a_file_it_promised_is_refused(): void
    {
        $result = $this->create()->handle();
        unlink($result['path'].'/database.jsonl');

        $this->expectException(BackupException::class);

        try {
            $this->restore()->verify($result['path']);
        } catch (BackupException $exception) {
            $this->assertSame('BACKUP_INCOMPLETE', $exception->reason);

            throw $exception;
        }
    }

    #[Test]
    public function a_backup_whose_checksum_does_not_match_is_refused(): void
    {
        $result = $this->create()->handle();
        file_put_contents($result['path'].'/database.jsonl', 'tampered', FILE_APPEND);

        // Restoring damage over working data is worse than not restoring, and
        // this is the check that makes the difference.
        $this->expectException(BackupException::class);

        try {
            $this->restore()->verify($result['path']);
        } catch (BackupException $exception) {
            $this->assertSame('BACKUP_CORRUPT', $exception->reason);

            throw $exception;
        }
    }

    #[Test]
    public function a_backup_from_a_newer_sanitube_is_refused_rather_than_guessed_at(): void
    {
        $result = $this->create()->handle();
        $this->rewriteManifest($result['path'], ['format' => BackupManifest::FORMAT + 1]);

        $this->expectException(BackupException::class);

        try {
            $this->restore()->verify($result['path']);
        } catch (BackupException $exception) {
            $this->assertSame('MANIFEST_UNREADABLE', $exception->reason);

            throw $exception;
        }
    }

    // --------------------------------------------------------- schema drift

    #[Test]
    public function a_backup_from_a_different_schema_is_refused_by_default(): void
    {
        $result = $this->create()->handle();
        $this->rewriteManifest($result['path'], [
            'migrations' => ['2019_01_01_000000_a_migration_this_database_never_ran'],
        ]);

        // Restoring older rows into a newer schema silently is how a catalogue
        // is corrupted. The refusal is the feature.
        $this->expectException(BackupException::class);

        try {
            $this->restore()->verify($result['path']);
        } catch (BackupException $exception) {
            $this->assertSame('SCHEMA_DRIFT', $exception->reason);

            throw $exception;
        }
    }

    #[Test]
    public function schema_drift_can_be_accepted_deliberately(): void
    {
        $result = $this->create()->handle();
        $this->rewriteManifest($result['path'], ['migrations' => ['2019_01_01_000000_something_else']]);

        // Available, and never the default. Somebody has to say they know.
        $verified = $this->restore()->verify($result['path'], allowSchemaDrift: true);

        $this->assertNotSame([], $verified['drift']);
    }

    // ------------------------------------------------------- path traversal

    #[Test]
    public function a_manifest_naming_a_path_outside_the_backup_is_refused(): void
    {
        $manifest = BackupManifest::describing('now', '1', 'sqlite', [], [], []);

        foreach (['../escape', '/etc/passwd', 'a/../../b', "nul\0byte", 'C:\\windows'] as $hostile) {
            try {
                $manifest->safePath('/tmp/backup', $hostile);
                $this->fail('safePath accepted ['.$hostile.']');
            } catch (BackupException $exception) {
                $this->assertSame('UNSAFE_PATH', $exception->reason);
            }
        }
    }

    #[Test]
    public function an_ordinary_path_inside_the_backup_is_allowed(): void
    {
        $manifest = BackupManifest::describing('now', '1', 'sqlite', [], [], []);

        $this->assertSame(
            '/tmp/backup/files/storage/app/public/cover.jpg',
            $manifest->safePath('/tmp/backup', 'files/storage/app/public/cover.jpg'),
        );
    }

    // ----------------------------------------------------------- the restore

    #[Test]
    public function a_restore_that_was_not_confirmed_changes_nothing(): void
    {
        $this->user('owner@example.test');
        $result = $this->create()->handle();

        User::query()->delete();

        $this->expectException(BackupException::class);

        try {
            $this->restore()->handle($result['path'], confirmed: false);
        } catch (BackupException $exception) {
            $this->assertSame('NOT_CONFIRMED', $exception->reason);
            $this->assertSame(0, User::query()->count(), 'A refused restore wrote to the database.');

            throw $exception;
        }
    }

    #[Test]
    public function the_dump_names_a_table_only_after_everything_it_points_at(): void
    {
        // The property a restore depends on, asserted over the real schema
        // rather than over a fixture, because the schema is what grows. Sorted
        // alphabetically — which is what this did first — `distribution_attempts`
        // is written before the `distribution_deliveries` it belongs to, and
        // restoring it fails on every engine that enforces foreign keys.
        //
        // Turning the constraints off is the usual answer and is also done,
        // but SQLite ignores `PRAGMA foreign_keys` inside a transaction, so a
        // restore leaning on that alone is green everywhere except the
        // platform's most common database.
        $schema = $this->app->make('db.connection')->getSchemaBuilder();
        $tables = $this->app->make(DatabaseDumper::class)->tables();

        $position = array_flip($tables);
        $checked = 0;

        foreach ($tables as $table) {
            foreach ($schema->getForeignKeys($table) as $key) {
                /** @var array{foreign_table?: string|null} $key */
                $referenced = $key['foreign_table'] ?? null;

                if (! is_string($referenced) || $referenced === $table || ! array_key_exists($referenced, $position)) {
                    continue;
                }

                $checked++;

                $this->assertLessThan(
                    $position[$table],
                    $position[$referenced],
                    sprintf(
                        '[%s] is dumped before [%s], which it points at. A restore would insert the child first.',
                        $referenced,
                        $table,
                    ),
                );
            }
        }

        // A schema with no foreign keys would pass the loop above without
        // checking anything, and this schema has plenty.
        $this->assertGreaterThan(10, $checked, 'The scan found suspiciously few foreign keys.');
    }

    #[Test]
    public function the_order_is_still_stable_between_runs(): void
    {
        // Dependency order must not cost determinism: a checksum over a dump
        // whose table order wobbles is a checksum that means nothing, and
        // "nothing changed since yesterday" stops being answerable.
        $dumper = $this->app->make(DatabaseDumper::class);

        $this->assertSame($dumper->tables(), $dumper->tables());
        $this->assertSame($dumper->tables(), $this->app->make(DatabaseDumper::class)->tables());
    }

    #[Test]
    public function a_restore_puts_back_a_row_that_points_at_another_row(): void
    {
        // The failure the acceptance walk found. Every restore test before
        // this one used `users`, a table nothing points at, so a dump order
        // that could not be replayed passed them all.
        $owner = $this->user('owner@example.test');

        $release = Release::query()->create([
            'title' => 'Night Bus',
            'type' => ReleaseType::Single,
            'language_code' => ContentLanguage::UNKNOWN,
            'status' => ReleaseStatus::Draft,
        ]);

        $delivery = DistributionDelivery::query()->create([
            'release_id' => $release->getKey(),
            'provider' => 'fake',
            'status' => DistributionDeliveryStatus::Draft,
            'idempotency_key' => str_repeat('a', 64),
        ]);

        DistributionAttempt::query()->create([
            'delivery_id' => $delivery->getKey(),
            'action' => DistributionAction::Submit,
            'outcome' => DistributionAttemptOutcome::Succeeded,
            'idempotency_key' => $delivery->idempotency_key,
        ]);

        $backup = $this->create()->handle();

        DistributionAttempt::query()->delete();
        DistributionDelivery::query()->delete();
        Release::query()->forceDelete();

        $this->restore()->handle($backup['path'], confirmed: true);

        $this->assertSame(1, Release::query()->count());
        $this->assertSame(1, DistributionDelivery::query()->count());
        $this->assertSame(1, DistributionAttempt::query()->count());
        $this->assertSame($owner->email, (string) User::query()->firstOrFail()->email);
    }

    #[Test]
    public function a_restore_over_a_populated_database_empties_children_before_parents(): void
    {
        // The other half of the ordering, and the half a restore actually
        // performs: the live database is *not* empty when an operator restores
        // over it. Emptying `releases` while `distribution_deliveries` still
        // point at them is refused by every engine that enforces foreign keys,
        // and the previous test cannot see it because it clears the rows by
        // hand first.
        $this->user('owner@example.test');

        $release = Release::query()->create([
            'title' => 'Night Bus',
            'type' => ReleaseType::Single,
            'language_code' => ContentLanguage::UNKNOWN,
            'status' => ReleaseStatus::Draft,
        ]);

        $delivery = DistributionDelivery::query()->create([
            'release_id' => $release->getKey(),
            'provider' => 'fake',
            'status' => DistributionDeliveryStatus::Draft,
            'idempotency_key' => str_repeat('b', 64),
        ]);

        DistributionAttempt::query()->create([
            'delivery_id' => $delivery->getKey(),
            'action' => DistributionAction::Submit,
            'outcome' => DistributionAttemptOutcome::Succeeded,
            'idempotency_key' => $delivery->idempotency_key,
        ]);

        $backup = $this->create()->handle();

        // Nothing is removed first. The rows the restore is about to replace
        // are still there, still referencing each other.
        $this->restore()->handle($backup['path'], confirmed: true);

        // Replaced, not merged, and not duplicated.
        $this->assertSame(1, Release::query()->count());
        $this->assertSame(1, DistributionDelivery::query()->count());
        $this->assertSame(1, DistributionAttempt::query()->count());
        $this->assertSame(
            $release->getKey(),
            DistributionDelivery::query()->firstOrFail()->release_id,
        );
    }

    #[Test]
    public function a_restore_puts_back_what_was_there(): void
    {
        $this->user('owner@example.test');
        $this->user('second@example.test');

        $result = $this->create()->handle();

        User::query()->where('email', 'second@example.test')->delete();
        $this->assertSame(1, User::query()->count());

        $restored = $this->restore()->handle($result['path'], confirmed: true);

        $this->assertSame(2, User::query()->count());
        $this->assertSame(1, User::query()->where('email', 'second@example.test')->count());
        $this->assertGreaterThan(0, $restored['rows']);
    }

    #[Test]
    public function a_restore_replaces_rather_than_merges(): void
    {
        $this->user('owner@example.test');
        $result = $this->create()->handle();

        $this->user('added-after-the-backup@example.test');
        $this->assertSame(2, User::query()->count());

        $this->restore()->handle($result['path'], confirmed: true);

        // A restore that left later rows in place would be a merge, and a
        // merge is not what somebody asks for when they restore a backup.
        $this->assertSame(1, User::query()->count());
        $this->assertSame(0, User::query()->where('email', 'added-after-the-backup@example.test')->count());
    }

    #[Test]
    public function verifying_changes_nothing(): void
    {
        $this->user('owner@example.test');
        $result = $this->create()->handle();

        User::query()->delete();

        $this->restore()->verify($result['path']);

        // The whole point of verify(): finding out whether a backup is
        // restorable at a moment when the answer costs nothing.
        $this->assertSame(0, User::query()->count());
    }

    // --------------------------------------------------------------- pruning

    #[Test]
    public function pruning_keeps_the_newest_and_removes_the_rest(): void
    {
        $paths = [];

        for ($i = 0; $i < 4; $i++) {
            $paths[] = $this->create()->handle()['path'];
        }

        $removed = $this->repository()->prune(keep: 2);

        $this->assertCount(2, $removed);
        $this->assertCount(2, $this->repository()->complete());
        $this->assertDirectoryExists($paths[3]);
        $this->assertDirectoryDoesNotExist($paths[0]);
    }

    #[Test]
    public function pruning_never_removes_everything_however_it_is_configured(): void
    {
        $newest = null;

        for ($i = 0; $i < 3; $i++) {
            $newest = $this->create()->handle()['path'];
        }

        // A mistyped environment variable must not mean "delete everything the
        // moment the next backup succeeds".
        $this->repository()->prune(keep: 0);

        $this->assertCount(1, $this->repository()->complete());
        $this->assertDirectoryExists((string) $newest);
    }

    #[Test]
    public function pruning_leaves_an_incomplete_backup_alone(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->create()->handle();
        }

        $orphan = $this->destination.'/1999-01-01_000000';
        mkdir($orphan, 0o750, true);

        $this->repository()->prune(keep: 1);

        // Either a run in progress or the evidence of a failure. Both are
        // things an operator should find, not have tidied away.
        $this->assertDirectoryExists($orphan);
    }

    #[Test]
    public function pruning_does_not_follow_a_symlink_out_of_the_backup_directory(): void
    {
        $outside = storage_path('framework/testing/not-a-backup-'.bin2hex(random_bytes(3)));
        mkdir($outside, 0o750, true);
        file_put_contents($outside.'/precious.txt', 'do not delete me');

        $first = $this->create()->handle()['path'];
        symlink($outside, $first.'/escape');

        $this->create()->handle();
        $this->repository()->prune(keep: 1);

        try {
            // The only recursive delete in the platform. A symlink placed
            // inside a backup is the one way it reaches the rest of the disk.
            $this->assertFileExists($outside.'/precious.txt');
        } finally {
            $this->rmrf($outside);
        }
    }

    // ------------------------------------------------------------ the commands

    #[Test]
    public function the_backup_command_prints_what_it_left_out(): void
    {
        $this->artisan('sanitube:backup')
            ->expectsOutputToContain('Not included:')
            ->expectsOutputToContain('files:audio-masters')
            ->assertSuccessful();
    }

    #[Test]
    public function a_restore_cannot_be_triggered_by_a_script_that_merely_lacks_a_terminal(): void
    {
        $this->user('owner@example.test');
        $result = $this->create()->handle();
        User::query()->delete();

        // The trap this guard exists for: a deploy script runs every command
        // non-interactively, and a restore that treated "no terminal" as
        // consent would be one stray line away from replacing a catalogue.
        $this->artisan('sanitube:restore', ['backup' => $result['path'], '--no-interaction' => true])
            ->expectsOutputToContain('--force')
            ->assertFailed();

        $this->assertSame(0, User::query()->count(), 'A restore ran without being confirmed.');
    }

    #[Test]
    public function forcing_a_restore_is_spelled_out_and_works(): void
    {
        $this->user('owner@example.test');
        $result = $this->create()->handle();
        User::query()->delete();

        $this->artisan('sanitube:restore', ['backup' => $result['path'], '--force' => true])
            ->assertSuccessful();

        $this->assertSame(1, User::query()->count());
    }

    #[Test]
    public function verifying_from_the_command_line_changes_nothing(): void
    {
        $this->user('owner@example.test');
        $result = $this->create()->handle();
        User::query()->delete();

        // The flag to put on a schedule: a backup nobody has ever tried to
        // restore is a belief rather than a backup.
        $this->artisan('sanitube:restore', ['backup' => $result['path'], '--verify' => true])
            ->expectsOutputToContain('restorable')
            ->assertSuccessful();

        $this->assertSame(0, User::query()->count());
    }

    #[Test]
    public function restoring_a_corrupt_backup_from_the_command_line_is_refused(): void
    {
        $this->user('owner@example.test');
        $result = $this->create()->handle();
        file_put_contents($result['path'].'/database.jsonl', 'tampered', FILE_APPEND);
        User::query()->delete();

        $this->artisan('sanitube:restore', ['backup' => $result['path'], '--force' => true])
            ->assertFailed();

        $this->assertSame(0, User::query()->count(), 'A corrupt backup was written over the database.');
    }

    #[Test]
    public function the_restore_command_defaults_to_the_most_recent_backup(): void
    {
        $this->create()->handle();
        $newest = $this->create()->handle()['path'];

        $this->artisan('sanitube:restore', ['--verify' => true])
            ->expectsOutputToContain($newest)
            ->assertSuccessful();
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

    private function repository(): BackupRepository
    {
        return $this->app->make(BackupRepository::class);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function rewriteManifest(string $directory, array $changes): void
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($directory.'/manifest.json'), true);

        file_put_contents(
            $directory.'/manifest.json',
            (string) json_encode(array_merge($decoded, $changes)),
        );
    }

    private function user(string $email): User
    {
        return User::factory()->create([
            'email' => $email,
            'role' => UserRole::Owner,
            'is_active' => true,
        ]);
    }

    private function rmrf(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        /** @var list<string> $entries */
        $entries = glob($directory.'/*') ?: [];

        foreach ($entries as $entry) {
            if (is_link($entry) || is_file($entry)) {
                unlink($entry);

                continue;
            }

            $this->rmrf($entry);
        }

        rmdir($directory);
    }
}
