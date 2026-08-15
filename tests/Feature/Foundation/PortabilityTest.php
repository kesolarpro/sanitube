<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Executable versions of the portability rules.
 *
 * These are the constraints that are easy to state and easy to break by
 * accident three months in, so they are asserted rather than documented.
 */
final class PortabilityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function no_domain_is_hard_coded_in_application_code(): void
    {
        $offenders = [];

        foreach ($this->phpSources() as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            // Any absolute http(s) URL outside a comment ties the install to a
            // host. Configuration must come from the environment instead.
            $matched = preg_match_all(
                '#["\']https?://(?!localhost|127\.0\.0\.1)[a-z0-9.-]+#i',
                $contents,
                $matches,
            );

            if ($matched > 0) {
                $offenders[$this->relative($file)] = array_unique($matches[0]);
            }
        }

        $this->assertSame([], $offenders, 'Hard-coded hosts found: '.json_encode($offenders));
    }

    #[Test]
    public function the_application_url_comes_from_the_environment(): void
    {
        $this->assertStringContainsString("env('APP_URL'", (string) file_get_contents(config_path('app.php')));
    }

    #[Test]
    public function no_absolute_server_path_is_baked_into_the_application(): void
    {
        $offenders = [];

        foreach ($this->phpSources() as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            if (preg_match_all('#["\'](/home/[a-z0-9_-]+|/var/www)/#i', $contents, $matches) > 0) {
                $offenders[$this->relative($file)] = array_unique($matches[0]);
            }
        }

        $this->assertSame([], $offenders, 'Absolute server paths found: '.json_encode($offenders));
    }

    #[Test]
    public function the_portable_drivers_are_the_defaults(): void
    {
        // Redis must never be required to boot: a shared cPanel account has none.
        $this->assertSame('database', $this->defaultFromExampleEnv('QUEUE_CONNECTION'));
        $this->assertSame('database', $this->defaultFromExampleEnv('CACHE_STORE'));
        $this->assertSame('database', $this->defaultFromExampleEnv('SESSION_DRIVER'));
        $this->assertSame('local', $this->defaultFromExampleEnv('SANITUBE_STORAGE_PROVIDER'));
    }

    #[Test]
    public function the_lock_file_is_resolved_for_the_minimum_supported_php(): void
    {
        // Without a pinned platform, Composer resolves against whatever PHP the
        // developer happens to run. A lock built on 8.4 installs *only* on 8.4,
        // so the shared-hosting floor the project promises silently stops
        // working — which is exactly how this was first discovered, in CI.
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);

        $required = $composer['require']['php'] ?? null;
        $platform = $composer['config']['platform']['php'] ?? null;

        $this->assertSame('^8.2', $required, 'The supported PHP floor changed.');
        $this->assertSame(
            '8.2',
            $platform,
            'composer.json must pin config.platform.php to the minimum supported PHP, '
                .'so composer.lock installs on every version the platform claims to support.',
        );
    }

    #[Test]
    public function no_locked_package_requires_more_than_the_minimum_supported_php(): void
    {
        $lock = json_decode((string) file_get_contents(base_path('composer.lock')), true);
        $offenders = [];

        foreach ([...$lock['packages'] ?? [], ...$lock['packages-dev'] ?? []] as $package) {
            $constraint = $package['require']['php'] ?? null;

            // Only flag a floor above 8.2. Alternations such as
            // "^8.2|^8.3|^8.4" allow 8.2 and are fine.
            if (is_string($constraint) && preg_match('/^\s*(>=\s*|\^)8\.([3-9])/', $constraint) === 1) {
                $offenders[] = $package['name'].' => '.$constraint;
            }
        }

        $this->assertSame([], $offenders, 'Locked packages exclude PHP 8.2: '.implode(', ', $offenders));
    }

    #[Test]
    public function the_example_environment_targets_mysql(): void
    {
        $this->assertSame('mysql', $this->defaultFromExampleEnv('DB_CONNECTION'));
    }

    #[Test]
    public function no_engine_specific_construct_is_used_in_any_migration(): void
    {
        // The schema has to be identical on SQLite, MySQL 8 and both MariaDB
        // releases. Each construct below either does not exist on one of them,
        // or exists with different semantics — and the difference only shows
        // up on whichever engine CI did not run.
        $forbidden = [
            'jsonb' => 'PostgreSQL-only type',
            '->json(' => 'not one type across the matrix: native binary on MySQL 8, an alias for '
                .'longtext/utf8mb4_bin on MariaDB. Use ->longText() and cast on the model',
            'ARRAY[' => 'PostgreSQL-only type',
            'tsvector' => 'PostgreSQL-only type',
            '->enum(' => 'SQL ENUM: changing a value means an ALTER TABLE, and semantics differ per engine',
            'CHECK (' => 'CHECK constraints are enforced inconsistently across the matrix',
            'storedAs' => 'generated column',
            'virtualAs' => 'generated column',
            'DEFAULT(uuid())' => 'engine-generated UUID default',
            'DEFAULT (uuid())' => 'engine-generated UUID default',
            'FULLTEXT' => 'engine-specific index type',
            'ENGINE=' => 'engine-specific table option',
        ];

        $offenders = [];

        foreach ($this->migrationFiles() as $migration) {
            $contents = (string) file_get_contents($migration);

            foreach ($forbidden as $needle => $why) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = sprintf('%s uses [%s] — %s', basename($migration), $needle, $why);
                }
            }
        }

        $this->assertSame([], $offenders, implode('; ', $offenders));
    }

    #[Test]
    public function every_migration_can_be_undone(): void
    {
        // A migration without a working down() turns a bad deployment into a
        // restore-from-backup.
        foreach ($this->migrationFiles() as $migration) {
            $contents = (string) file_get_contents($migration);

            $this->assertStringContainsString(
                'public function down(): void',
                $contents,
                sprintf('%s has no down() method.', basename($migration)),
            );

            $this->assertMatchesRegularExpression(
                '/public function down\(\): void\s*\{\s*\S/',
                $contents,
                sprintf('%s has an empty down() method.', basename($migration)),
            );
        }
    }

    #[Test]
    public function domain_tables_use_string_columns_for_enumerated_values(): void
    {
        // Statuses and types are PHP backed enums, stored as VARCHAR. Adding a
        // case must be a code change, never a schema migration on a table with
        // hundreds of thousands of rows.
        foreach ([
            'tracks' => ['status', 'source'],
            'releases' => ['status', 'type'],
            'artists' => ['status', 'type'],
            'assets' => ['kind', 'status'],
            'external_identifiers' => ['type', 'source'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                // Engines spell it differently — `varchar` here, `varchar(32)`
                // there — so the assertion is on the family, not the exact
                // name. What must never appear is `enum`.
                $type = strtolower(Schema::getColumnType($table, $column));

                $this->assertStringContainsString(
                    'char',
                    $type,
                    sprintf('[%s.%s] should be a string column backed by a PHP enum, got [%s].', $table, $column, $type),
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private function migrationFiles(): array
    {
        return array_map(
            strval(...),
            array_merge(
                (array) glob(database_path('migrations/*.php')),
                (array) glob(base_path('src/*/Database/Migrations/*.php')),
            ),
        );
    }

    #[Test]
    public function every_config_file_parses_and_returns_an_array(): void
    {
        foreach ((array) glob(config_path('*.php')) as $file) {
            $this->assertIsArray(require (string) $file, basename((string) $file).' must return an array.');
        }
    }

    /**
     * @return list<SplFileInfo>
     */
    private function phpSources(): array
    {
        $files = [];

        foreach ([base_path('src'), base_path('app'), base_path('routes'), base_path('config')] as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file;
                }
            }
        }

        return $files;
    }

    private function relative(SplFileInfo $file): string
    {
        return str_replace(base_path().'/', '', $file->getPathname());
    }

    private function defaultFromExampleEnv(string $key): ?string
    {
        $contents = (string) file_get_contents(base_path('.env.example'));

        return preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $contents, $matches) === 1
            ? trim($matches[1])
            : null;
    }
}
