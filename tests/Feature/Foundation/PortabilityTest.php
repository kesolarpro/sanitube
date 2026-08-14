<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

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
    public function no_postgresql_specific_column_type_is_used_in_migrations(): void
    {
        // The schema must stay portable to MySQL/MariaDB, the only engines
        // shared hosting offers.
        $forbidden = ['jsonb', '->tsvector(', '->ipAddress()->useCurrent()', 'ARRAY['];

        foreach ((array) glob(database_path('migrations/*.php')) as $migration) {
            $contents = (string) file_get_contents((string) $migration);

            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $contents,
                    sprintf('%s uses the non-portable construct [%s].', basename((string) $migration), $needle),
                );
            }
        }
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
