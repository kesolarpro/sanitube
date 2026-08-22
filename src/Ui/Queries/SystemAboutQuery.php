<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use PDO;
use SaniTube\Deployment\Frontend\FrontendBuildInstaller;
use SaniTube\Deployment\ProductionCheck;
use SaniTube\Deployment\Services\DiagnoseProduction;
use Throwable;

/**
 * What this installation actually is, and whether it is well.
 *
 * SYS-001. `sanitube:doctor` has existed since DEP-016 and reported nineteen
 * things worth knowing about a live installation — a queue running inline, a
 * directory that is not writable, an upload ceiling the host will not honour —
 * to a terminal. Which meant the operator most likely to need it, the one on
 * shared hosting who reached this platform *because* they did not want a
 * shell, could not read a word of it.
 *
 * The identity half is worse than missing: it was never recorded. `app.version`
 * was read by the backup manifest and set by nothing, so every backup this
 * platform has ever written says `unknown` — and "which release am I running"
 * had no answer anywhere in the product.
 *
 * **Nothing here is invented.** A version the platform made up would be worse
 * than none, because the question somebody asks this screen is "am I on the
 * release that fixed the thing", and a hardcoded string answers yes for ever.
 * Every field is null when it was not recorded, and the screen says which.
 *
 * **Read from files, never from a shell.** The commit comes out of `.git` by
 * reading three files. Shelling out to `git` would mean this platform executes
 * a binary to render a page — on a host that may not have it, from a request
 * anyone with an administrator session can make.
 */
final readonly class SystemAboutQuery
{
    public function __construct(
        private Repository $config,
        private Connection $connection,
        private DiagnoseProduction $doctor,
        private FrontendBuildInstaller $frontend,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        return [
            'installation' => $this->installation(),
            'runtime' => $this->runtime(),
            'migrations' => $this->migrations(),
            'diagnosis' => $this->diagnosis(),
        ];
    }

    /**
     * Which code this is.
     *
     * @return array<string, mixed>
     */
    private function installation(): array
    {
        $version = $this->config->get('app.version');
        $build = $this->frontendBuild();

        return [
            // Null when the deployment recorded nothing, which is the honest
            // state of a checkout somebody pulled by hand.
            'version' => is_string($version) && trim($version) !== '' ? trim($version) : null,

            // Null when this is not a git checkout — a release tarball, a
            // cPanel upload. Not a failure: it is simply a deployment that
            // does not carry its own history.
            'commit' => $this->frontend->headCommit(),

            'environment' => (string) $this->config->get('app.env'),

            // The one setting whose being wrong is both invisible and serious.
            'debug' => (bool) $this->config->get('app.debug'),

            'locale' => (string) $this->config->get('app.locale'),

            // Which frontend is actually being served, as the installer
            // recorded it. Written since DEP-011 and read by nothing until
            // now: an installation whose PHP was updated and whose assets
            // were not is exactly the state this answers.
            'frontend' => $build,
        ];
    }

    /**
     * What it is running on.
     *
     * The database *server* version, not a credential and not a connection
     * string. "Which MariaDB am I on" is the question behind half the
     * compatibility problems an operator meets, and it was answerable only
     * over SSH.
     *
     * @return array<string, mixed>
     */
    private function runtime(): array
    {
        return [
            'php' => PHP_VERSION,
            'database_driver' => $this->connection->getDriverName(),
            'database_version' => $this->databaseVersion(),
            // Whether `config:cache` has been run. It changes what editing a
            // .env file does — nothing, until the cache is rebuilt.
            'config_cached' => file_exists(base_path('bootstrap/cache/config.php')),
        ];
    }

    /**
     * Whether the schema is the one this code expects.
     *
     * **The count is not the answer; the difference is.** An installation
     * whose files were updated and whose migrations were not runs code against
     * a schema that is missing columns, and it fails at the first write rather
     * than at boot. That is the failure this panel exists to make visible
     * before somebody's import finds it.
     *
     * @return array<string, mixed>
     */
    private function migrations(): array
    {
        $applied = $this->appliedMigrations();

        if ($applied === null) {
            return ['measured' => false, 'applied' => null, 'pending' => null, 'latest' => null];
        }

        $onDisk = $this->migrationsOnDisk();
        $pending = array_values(array_diff($onDisk, $applied));

        sort($pending);

        return [
            'measured' => true,
            'applied' => count($applied),
            // Named rather than counted. "Three pending" tells an operator
            // there is a problem; the names tell them what changed.
            'pending' => $pending,
            'latest' => $applied === [] ? null : $applied[count($applied) - 1],
        ];
    }

    /**
     * The doctor, rendered rather than printed.
     *
     * `UNKNOWN` is carried through as itself and never counted as ready. An
     * installation whose database cannot be reached does not have a healthy
     * schema; it has no answer, and reporting no answer as a pass is how a
     * screen reassures somebody about a server that is already down.
     *
     * @return array<string, mixed>
     */
    private function diagnosis(): array
    {
        try {
            $checks = $this->doctor->handle();
        } catch (Throwable) {
            // A page that 500s because the diagnosis failed would be the
            // screen somebody opens *because* something is wrong, refusing to
            // open for the same reason.
            return ['measured' => false, 'counts' => [], 'checks' => []];
        }

        $counts = [
            ProductionCheck::READY => 0,
            ProductionCheck::WARNING => 0,
            ProductionCheck::BLOCKER => 0,
            ProductionCheck::UNKNOWN => 0,
        ];

        $rows = [];

        foreach ($checks as $check) {
            $counts[$check->verdict] = ($counts[$check->verdict] ?? 0) + 1;

            $rows[] = [
                'section' => $check->section,
                'key' => $check->key,
                'verdict' => $check->verdict,
                'summary' => $check->summary,
                'remediation' => $check->remediation,
            ];
        }

        return ['measured' => true, 'counts' => $counts, 'checks' => $rows];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function frontendBuild(): ?array
    {
        $raw = @file_get_contents(storage_path('framework/frontend-build.json'));

        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return null;
        }

        $sha = $decoded['sha'] ?? null;
        $installedAt = $decoded['installed_at'] ?? null;

        return [
            'sha' => is_string($sha) ? $sha : null,
            'installed_at' => is_string($installedAt) ? $installedAt : null,
        ];
    }

    /**
     * @return list<string>|null
     */
    private function appliedMigrations(): ?array
    {
        try {
            if (! $this->connection->getSchemaBuilder()->hasTable('migrations')) {
                return null;
            }

            /** @var list<string> $names */
            $names = $this->connection->table('migrations')->orderBy('migration')->pluck('migration')->all();
        } catch (QueryException) {
            return null;
        }

        return $names;
    }

    /**
     * Every migration this code carries, whatever module declares it.
     *
     * Read from the registered paths rather than from one directory: the
     * modules under `src/` each ship their own, and a scan of
     * `database/migrations` would report every one of them as pending.
     *
     * @return list<string>
     */
    private function migrationsOnDisk(): array
    {
        $names = [];

        foreach (app('migrator')->paths() as $path) {
            foreach ((array) glob($path.'/*.php') as $file) {
                if (is_string($file)) {
                    $names[] = basename($file, '.php');
                }
            }
        }

        foreach ((array) glob(database_path('migrations').'/*.php') as $file) {
            if (is_string($file)) {
                $names[] = basename($file, '.php');
            }
        }

        return array_values(array_unique($names));
    }

    private function databaseVersion(): ?string
    {
        try {
            $pdo = $this->connection->getPdo();
            $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (Throwable) {
            return null;
        }

        return is_string($version) && $version !== '' ? $version : null;
    }
}
