<?php

declare(strict_types=1);

namespace SaniTube\Deployment\Services;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migrator;
use SaniTube\Deployment\BackupManifest;
use SaniTube\Deployment\ProductionCheck;
use SaniTube\Observability\Capabilities\CapabilityRegistry;
use SaniTube\Observability\Capabilities\CapabilityStatus;
use SaniTube\Observability\SchedulerHeartbeat;
use SaniTube\Production\Enums\AutonomyMode;
use Throwable;

/**
 * Everything that is wrong with this installation for production use.
 *
 * **Read-only, always.** It probes nothing it has to write to, starts no job
 * and changes no state — it is the command an operator runs on a live server
 * at the moment they are least willing to have something else happen. A doctor
 * that fixed things would be a deploy script with a reassuring name.
 *
 * The checks are grouped the way an operator thinks about a server, and every
 * failure carries the fix rather than a diagnosis. "APP_DEBUG is true" is not
 * useful on its own; "set APP_DEBUG=false — it leaks stack traces, environment
 * variables and database credentials to anyone who can trigger an error" is.
 *
 * **Nothing here prints a secret.** Not truncated, not masked, not "for
 * convenience". The checks report whether a value is *configured*, never what
 * it is, because a diagnostic is exactly the output somebody pastes into a
 * support thread.
 *
 * Most of these pass on a developer's machine and are serious in production,
 * which is why this is not folded into `sanitube:health` — that reports what
 * the *server* can do, and asks the same question in both places.
 */
final readonly class DiagnoseProduction
{
    public function __construct(
        private Config $config,
        private Connection $connection,
        private Migrator $migrator,
        private CapabilityRegistry $capabilities,
        private SchedulerHeartbeat $heartbeat,
        private BackupRepository $backups,
    ) {}

    /**
     * @return list<ProductionCheck>
     */
    public function handle(): array
    {
        return [
            ...$this->application(),
            ...$this->database(),
            ...$this->storage(),
            ...$this->workers(),
            ...$this->operations(),
            ...$this->assistedFeatures(),
            ...$this->server(),
        ];
    }

    /**
     * @return list<ProductionCheck>
     */
    private function application(): array
    {
        $checks = [];
        $production = $this->isProduction();

        $checks[] = is_string($this->config->get('app.key')) && $this->config->get('app.key') !== ''
            ? ProductionCheck::ready('App', 'app_key', 'An application key is set.')
            : ProductionCheck::blocker(
                'App',
                'app_key',
                'No application key.',
                'Run `php artisan key:generate`. Without it every encrypted value and signed URL '
                    .'is unreadable — and generating one later invalidates the ones already issued.',
            );

        // The single most consequential production setting in Laravel, and the
        // one most often left as the framework ships it.
        if ($this->config->get('app.debug') === true) {
            $checks[] = $production
                ? ProductionCheck::blocker(
                    'App',
                    'app_debug',
                    'Debug mode is on.',
                    'Set APP_DEBUG=false. It renders stack traces, environment variables and '
                        .'database credentials to anyone who can trigger an error.',
                )
                : ProductionCheck::warning(
                    'App',
                    'app_debug',
                    'Debug mode is on, which is right outside production.',
                    'Make sure APP_DEBUG=false wherever this is deployed.',
                );
        } else {
            $checks[] = ProductionCheck::ready('App', 'app_debug', 'Debug mode is off.');
        }

        $url = (string) $this->config->get('app.url');

        if ($url === '' || $url === 'http://localhost') {
            $checks[] = $production
                ? ProductionCheck::blocker(
                    'App',
                    'app_url',
                    'APP_URL is unset or still points at localhost.',
                    'Set APP_URL to the address people actually use. Signed URLs, emailed links '
                        .'and queued jobs all build absolute addresses from it.',
                )
                : ProductionCheck::warning('App', 'app_url', 'APP_URL still points at localhost.');
        } elseif ($production && ! str_starts_with($url, 'https://')) {
            $checks[] = ProductionCheck::blocker(
                'App',
                'app_url',
                'APP_URL is not https.',
                'Serve the application over TLS and set APP_URL accordingly. Session cookies and '
                    .'signed preview links travel over it.',
            );
        } else {
            $checks[] = ProductionCheck::ready('App', 'app_url', 'APP_URL is set.');
        }

        return $checks;
    }

    /**
     * @return list<ProductionCheck>
     */
    private function database(): array
    {
        try {
            $this->connection->getPdo();
        } catch (Throwable $exception) {
            // UNKNOWN, not a blocker about migrations: nothing downstream was
            // measured, and reporting "schema out of date" would be inventing
            // a finding from a question that never completed.
            return [ProductionCheck::unknown(
                'Database',
                'connection',
                'The database could not be reached, so nothing about it could be checked.',
                'Check the database credentials and that the server is running.',
            )];
        }

        $checks = [ProductionCheck::ready('Database', 'connection', 'The database is reachable.')];

        try {
            $pending = count($this->migrator->getMigrationFiles($this->migrator->paths()))
                - count($this->migrator->getRepository()->getRan());

            $checks[] = $pending > 0
                ? ProductionCheck::blocker(
                    'Database',
                    'migrations',
                    sprintf('%d migration(s) have not been run.', $pending),
                    'Run `php artisan sanitube:deploy`, which migrates without creating anything.',
                )
                : ProductionCheck::ready('Database', 'migrations', 'The schema is up to date.');
        } catch (Throwable) {
            $checks[] = ProductionCheck::unknown(
                'Database',
                'migrations',
                'The migration state could not be read.',
                'Usually means the migrations table does not exist yet — run the installer.',
            );
        }

        return $checks;
    }

    /**
     * @return list<ProductionCheck>
     */
    private function storage(): array
    {
        $checks = [];

        foreach (['storage/app', 'storage/framework', 'storage/logs', 'bootstrap/cache'] as $relative) {
            $path = base_path($relative);

            if (is_dir($path) && is_writable($path)) {
                continue;
            }

            $checks[] = ProductionCheck::blocker(
                'Storage',
                'writable:'.$relative,
                sprintf('[%s] is not writable.', $relative),
                'Give the web server user write access. On cPanel this is usually the account '
                    .'owner; the application cannot log, cache or queue without it.',
            );
        }

        if ($checks === []) {
            $checks[] = ProductionCheck::ready('Storage', 'writable', 'Every directory the application writes to is writable.');
        }

        // A backup destination inside the web root is the whole database
        // available over HTTP. `BackupRepository::destination()` already
        // refuses one — the check here is that the configured value resolves
        // at all, and the refusal it throws is reported as the blocker it is.
        //
        // Deliberately not re-testing `str_starts_with(public_path())` after
        // calling it: a mutation pass showed that branch is unreachable, and
        // an unreachable second opinion is one that can quietly start
        // disagreeing with the guard it duplicates.
        try {
            $this->backups->destination();

            $checks[] = ProductionCheck::ready(
                'Storage',
                'backup_destination',
                'The backup destination resolves and is outside the web root.',
            );
        } catch (Throwable $exception) {
            $checks[] = ProductionCheck::blocker(
                'Storage',
                'backup_destination',
                'The backup destination is not usable.',
                $exception->getMessage(),
            );
        }

        return $checks;
    }

    /**
     * @return list<ProductionCheck>
     */
    private function workers(): array
    {
        $checks = [];
        $queue = (string) $this->config->get('queue.default');

        if ($queue === 'sync') {
            $checks[] = ProductionCheck::blocker(
                'Queue',
                'driver',
                'The queue runs work inline.',
                'Configure a real queue connection and run a worker. On `sync`, importing nine '
                    .'hundred files happens inside one web request, which will time out.',
            );
        } else {
            $checks[] = ProductionCheck::ready('Queue', 'driver', sprintf('Queue driver: %s.', $queue));
        }

        $lastRun = $this->heartbeat->lastRunAt();

        if ($lastRun === null) {
            // Never, not zero. A scheduler that has not run is not a scheduler
            // that ran and found nothing to do.
            $checks[] = ProductionCheck::warning(
                'Scheduler',
                'heartbeat',
                'The scheduler has never reported running.',
                'Add the cron entry from the deployment guide. Without it nothing scheduled '
                    .'happens — including pruning and health refreshes.',
            );
        } else {
            $age = time() - $lastRun->getTimestamp();

            $checks[] = $age > 3600
                ? ProductionCheck::warning(
                    'Scheduler',
                    'heartbeat',
                    sprintf('The scheduler last ran %d minute(s) ago.', intdiv($age, 60)),
                    'Check the cron entry is still installed and the account can run it.',
                )
                : ProductionCheck::ready('Scheduler', 'heartbeat', 'The scheduler is running.');
        }

        return $checks;
    }

    /**
     * @return list<ProductionCheck>
     */
    private function operations(): array
    {
        try {
            $backups = $this->backups->complete();
        } catch (Throwable) {
            return [ProductionCheck::unknown(
                'Backup',
                'freshness',
                'The backup directory could not be read.',
                'Check that backup.destination exists and is readable.',
            )];
        }

        if ($backups === []) {
            return [ProductionCheck::warning(
                'Backup',
                'freshness',
                'This installation has never been backed up.',
                'Run `php artisan sanitube:backup` and schedule it. A restore is only as good '
                    .'as the most recent backup.',
            )];
        }

        // Taken from the manifest rather than from the directory's mtime: the
        // manifest records when the backup was *made*, and a directory's
        // timestamp is whatever last touched it — a copy, a sync, a permission
        // change. Asking the wrong one reports a fresh backup that is months
        // old because something walked the folder.
        /** @var array{path: string, manifest: BackupManifest} $newest */
        $newest = $backups[0];
        $createdAt = strtotime($newest['manifest']->createdAt);

        if ($createdAt === false) {
            return [ProductionCheck::unknown('Backup', 'freshness', 'The age of the newest backup could not be read.')];
        }

        $age = time() - $createdAt;

        return [$age > 7 * 86400
            ? ProductionCheck::warning(
                'Backup',
                'freshness',
                sprintf('The newest backup is %d day(s) old.', intdiv($age, 86400)),
                'Schedule `sanitube:backup`. A week-old backup is a week of work to re-do.',
            )
            : ProductionCheck::ready('Backup', 'freshness', 'A recent backup exists.')];
    }

    /**
     * The optional, assisted features — transcription, enrichment, artwork,
     * generation.
     *
     * **Nothing here is ever a blocker.** Every one of these is optional by
     * design: an installation with no AI account imports, catalogues, validates
     * and delivers exactly as before. Reporting an absent optional feature as a
     * blocker would make a correctly-configured shared host look broken, which
     * is the failure the whole capability model exists to avoid.
     *
     * What they *do* report is the difference between "not configured" and
     * "configured and cannot work", because those need different actions and
     * only one of them is a mistake.
     *
     * @return list<ProductionCheck>
     */
    private function assistedFeatures(): array
    {
        $checks = [
            $this->providerCheck('Transcription', 'provider', 'transcription.default'),
            $this->providerCheck('AI', 'provider', 'ai.default'),
        ];

        $checks[] = $this->artworkGenerationCheck();
        $checks[] = $this->unattendedReleaseCheck();

        return $checks;
    }

    /**
     * Whether an optional provider is configured — never which one's key.
     */
    private function providerCheck(string $section, string $key, string $setting): ProductionCheck
    {
        $configured = $this->config->get($setting);
        $configured = is_string($configured) ? trim($configured) : '';

        if ($configured === '' || $configured === 'none' || $configured === 'null') {
            return ProductionCheck::ready(
                $section,
                $key,
                'No provider is configured. This feature is optional and everything else works without it.',
            );
        }

        // Named, not valued. The provider's *name* is a configuration choice an
        // operator made; its key is a secret, and this output is exactly what
        // somebody pastes into a support thread.
        return ProductionCheck::ready($section, $key, sprintf('Provider configured: %s.', $configured));
    }

    /**
     * Whether artwork generation could ever produce something this installation
     * would accept.
     *
     * The check ART-002 exists to make visible. A provider can be configured,
     * credentialled and working, and still be unable to satisfy this
     * installation's own artwork requirements — because the sizes it produces
     * are smaller than `artwork.requirements.minimum_pixels`. Left unreported,
     * that is discovered as a refusal at the moment somebody wanted a cover.
     */
    private function artworkGenerationCheck(): ProductionCheck
    {
        $provider = $this->config->get('artwork.default_provider');
        $provider = is_string($provider) ? trim($provider) : '';

        if ($provider === '' || $provider === 'none') {
            return ProductionCheck::ready(
                'Artwork',
                'generation',
                'No image provider is configured. Covers are attached by hand, which is the default.',
            );
        }

        $minimum = (int) $this->config->get('artwork.requirements.minimum_pixels', 0);
        $sizes = $this->config->get('artwork.providers.'.$provider.'.sizes');
        $largest = 0;

        foreach (is_array($sizes) ? $sizes : [] as $size) {
            if (! is_string($size) || preg_match('/^(\d+)x(\d+)$/', trim($size), $matches) !== 1) {
                continue;
            }

            $largest = max($largest, min((int) $matches[1], (int) $matches[2]));
        }

        if ($minimum > 0 && $largest > 0 && $largest < $minimum) {
            return ProductionCheck::warning(
                'Artwork',
                'generation',
                sprintf(
                    'The image provider is configured but cannot satisfy this installation: it produces '
                        .'at most %dpx and the artwork requirement is %dpx.',
                    $largest,
                    $minimum,
                ),
                'Nothing is spent — generation refuses before sending. Either lower '
                    .'SANITUBE_ARTWORK_MINIMUM_PIXELS, or declare a larger size your account and model '
                    .'actually support in config/artwork.php.',
            );
        }

        return ProductionCheck::ready('Artwork', 'generation', 'The image provider can meet the artwork requirements.');
    }

    /**
     * That unattended release is unavailable.
     *
     * Reported as a *reassurance* rather than a problem, and reported at all
     * because it is the one thing an operator most needs to be able to confirm
     * without reading the source: nothing on this platform hands a release to a
     * distributor without a person.
     */
    private function unattendedReleaseCheck(): ProductionCheck
    {
        foreach (AutonomyMode::cases() as $mode) {
            if ($mode->mayReleaseUnattended()) {
                return ProductionCheck::blocker(
                    'Production',
                    'unattended_release',
                    'An autonomy mode reports that it may release without a person.',
                    'This must never be true. AutonomyMode::AutonomousRelease is locked in code; if this '
                        .'check fails, that lock has been removed and delivery is no longer supervised.',
                );
            }
        }

        return ProductionCheck::ready(
            'Production',
            'unattended_release',
            'No autonomy mode can hand a release to a distributor without a person.',
        );
    }

    /**
     * @return list<ProductionCheck>
     */
    private function server(): array
    {
        $checks = [];

        // Composed rather than re-derived: `sanitube:health` owns the question
        // of what this machine can do, and a second implementation here is the
        // copy that eventually disagrees with it.
        foreach ($this->capabilities->report()->all() as $capability) {
            if ($capability->status === CapabilityStatus::Unavailable && $capability->required) {
                $checks[] = ProductionCheck::blocker(
                    'Server',
                    $capability->key,
                    $capability->detail ?? $capability->label.' is unavailable.',
                    $capability->remediation ?? 'See `php artisan sanitube:health`.',
                );
            }
        }

        if ($checks === []) {
            $checks[] = ProductionCheck::ready('Server', 'capabilities', 'Every required capability is available.');
        }

        return $checks;
    }

    private function isProduction(): bool
    {
        return $this->config->get('app.env') === 'production';
    }
}
