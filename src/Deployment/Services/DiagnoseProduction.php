<?php

declare(strict_types=1);

namespace SaniTube\Deployment\Services;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migrator;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Deployment\BackupContents;
use SaniTube\Deployment\BackupManifest;
use SaniTube\Deployment\Exceptions\BackupException;
use SaniTube\Deployment\ProductionCheck;
use SaniTube\Media\Services\FingerprintAsset;
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
    /**
     * How long a runnable job may sit before the queue reads as unworked.
     *
     * Fifteen minutes: long enough that a burst from a large import drains or
     * gets reserved first, short enough that an operator finds out on the day
     * rather than from a week of missing candidates.
     */
    private const QUEUE_STALE_SECONDS = 900;

    /**
     * Degraded capabilities this command already asks about itself, and where.
     *
     * Not a list of things that do not matter — a list of things that would be
     * said twice. This class's own comment on the backup destination puts it
     * best: a second opinion is one that can quietly start disagreeing with the
     * guard it duplicates. Where the doctor has its own check, that check wins,
     * because it says more.
     *
     * @var array<string, string>
     */
    private const ANSWERED_ELSEWHERE = [
        'queue' => 'Queue/driver blocks on `sync` rather than warning, and Queue/backlog answers whether '
            .'anything is working it.',
        'scheduler' => 'Scheduler/heartbeat reports the same staleness with the cron entry to fix it.',
    ];

    public function __construct(
        private Config $config,
        private Connection $connection,
        private Migrator $migrator,
        private CapabilityRegistry $capabilities,
        private SchedulerHeartbeat $heartbeat,
        private BackupRepository $backups,
        private BackupContents $contents,
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
            ...$this->deduplication(),
            ...$this->assistedFeatures(),
            ...$this->server(),
            ...$this->disk(),
        ];
    }

    /**
     * Free space where the application and its backups live.
     *
     * Absolute thresholds, configured in megabytes (operations.disk): what
     * the platform needs — room for a master upload, a backup, a log — is
     * measured in megabytes, not in fractions of somebody's disk. Two
     * volumes checked separately, because a backup destination on another
     * filesystem full while the application volume is roomy is exactly the
     * failure a single check averages away.
     *
     * @return list<ProductionCheck>
     */
    private function disk(): array
    {
        $warn = (int) config('operations.disk.warn_below_mb', 2048);
        $blocker = (int) config('operations.disk.blocker_below_mb', 256);

        $volumes = ['application_disk' => base_path()];

        $backupDestination = (string) config('backup.destination');

        if ($backupDestination !== '' && is_dir($backupDestination)) {
            $volumes['backup_disk'] = $backupDestination;
        }

        $checks = [];

        foreach ($volumes as $key => $path) {
            $free = @disk_free_space($path);

            if ($free === false) {
                $checks[] = ProductionCheck::unknown(
                    'Server',
                    $key,
                    sprintf('Free space could not be read for the %s volume.', str_replace('_disk', '', $key)),
                    'open_basedir or a restricted filesystem can hide this; check df by hand.',
                );

                continue;
            }

            $freeMb = (int) floor($free / 1048576);

            if ($blocker > 0 && $freeMb < $blocker) {
                $checks[] = ProductionCheck::blocker(
                    'Server',
                    $key,
                    sprintf('%d MB free. A deploy on a full disk fails halfway in the worst possible way.', $freeMb),
                    'Free space before doing anything else: old backups, logs, stale artifacts.',
                );

                continue;
            }

            if ($warn > 0 && $freeMb < $warn) {
                $checks[] = ProductionCheck::warning(
                    'Server',
                    $key,
                    sprintf('%d MB free, below the %d MB warning threshold.', $freeMb, $warn),
                    'Make room, or raise SANITUBE_DISK_WARN_MB if this volume is genuinely fine.',
                );

                continue;
            }

            $checks[] = ProductionCheck::ready('Server', $key, sprintf('%d MB free.', $freeMb));
        }

        return $checks;
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

        foreach ($this->queueBacklog($queue) as $check) {
            $checks[] = $check;
        }

        foreach ($this->failedWork() as $check) {
            $checks[] = $check;
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
     * Whether anything is actually working the queue.
     *
     * The driver check above answers "is work queued rather than run inline".
     * It does not answer "does anybody pick it up", and those fail separately:
     * a `database` queue with no `queue:work` behind it accepts every job,
     * keeps every job, and completes none. Both deployment guides tell an
     * operator to add that cron or that systemd unit, and nothing until now
     * checked that they did.
     *
     * **A count cannot answer this.** Fifty jobs queued a second ago and fifty
     * queued three days ago are the same number; what separates a busy queue
     * from a dead one is how long the oldest one that is *ready to run* has
     * been waiting. Reserved jobs are excluded on purpose — a job a worker is
     * holding is a worker doing its job, however slow.
     *
     * A warning rather than a blocker. A large import legitimately queues a
     * burst, and a fresh installation mid-backfill is not misconfigured. What
     * it is not is silent.
     *
     * @return list<ProductionCheck>
     */
    private function queueBacklog(string $queue): array
    {
        // Only the database driver keeps work where this can see it. Redis and
        // SQS hold their own, and inventing an answer from an unused `jobs`
        // table would report an empty queue on an installation whose real one
        // is backed up.
        if ((string) $this->config->get('queue.connections.'.$queue.'.driver') !== 'database') {
            return [];
        }

        $table = (string) $this->config->get('queue.connections.'.$queue.'.table', 'jobs');

        if (! $this->connection->getSchemaBuilder()->hasTable($table)) {
            return [];
        }

        // The oldest unreserved job, due or not.
        //
        // There is deliberately no `available_at <= now` filter. A delayed job
        // yields a *negative* wait below and can never read as a backlog, and
        // `min()` returns the smallest timestamp — so a job scheduled for next
        // week can never hide one that has been sitting since yesterday. A
        // filter here would be a branch no test could kill, which is a branch
        // that stops meaning anything.
        /** @var int|null $oldest */
        $oldest = $this->connection->table($table)
            ->whereNull('reserved_at')
            ->min('available_at');

        if ($oldest === null) {
            return [ProductionCheck::ready('Queue', 'backlog', 'Nothing is waiting to be picked up.')];
        }

        $waited = time() - (int) $oldest;

        if ($waited < self::QUEUE_STALE_SECONDS) {
            return [ProductionCheck::ready('Queue', 'backlog', 'Work is being picked up.')];
        }

        return [ProductionCheck::warning(
            'Queue',
            'backlog',
            sprintf(
                'The oldest job ready to run has been waiting %d minute(s). Nothing appears to be working the queue.',
                intdiv($waited, 60),
            ),
            'Start a worker. On a VPS that is the systemd unit in docs/deployment/vps.md; on a shared '
                .'account it is the `queue:work --stop-when-empty` cron in docs/deployment/installation.md. '
                .'Until one runs, imports and analysis are accepted and never completed.',
        )];
    }

    /**
     * Work that was picked up and did not finish.
     *
     * The backlog check above cannot see this, and that is the point of having
     * both. A worker consuming briskly and failing every job leaves `jobs`
     * empty — so the backlog reads READY — while `failed_jobs` fills and the
     * catalogue quietly stops growing. "Nobody is working the queue" and
     * "everything the queue does fails" look identical from a job count and are
     * opposite problems.
     *
     * Any row at all is worth saying. `ResolveFailedJob` deletes on resolve, so
     * what is left is work somebody has not yet decided about — not history.
     *
     * A warning: a failed job is lost work, not a broken installation, and the
     * screen that lists them is where the decision belongs.
     *
     * @return list<ProductionCheck>
     */
    private function failedWork(): array
    {
        // Laravel stores failed jobs under `queue.failed`, independently of the
        // connection that runs them: a Redis queue on an installation that
        // still records its failures in the database is an ordinary shape.
        $table = (string) $this->config->get('queue.failed.table', 'failed_jobs');

        if ((string) $this->config->get('queue.failed.driver') === 'null') {
            return [ProductionCheck::warning(
                'Queue',
                'failed_work',
                'Failed jobs are discarded rather than recorded.',
                'Set QUEUE_FAILED_DRIVER to database-uuids. On `null`, work that fails leaves no trace '
                    .'and nobody can find out what did not happen.',
            )];
        }

        if (! $this->connection->getSchemaBuilder()->hasTable($table)) {
            return [];
        }

        $failed = $this->connection->table($table)->count();

        if ($failed === 0) {
            return [ProductionCheck::ready('Queue', 'failed_work', 'No work is waiting to be looked at.')];
        }

        return [ProductionCheck::warning(
            'Queue',
            'failed_work',
            sprintf('%d job(s) failed and nobody has decided what to do about them.', $failed),
            'Open the failed-jobs screen. Each one is work that was accepted and never completed — an '
                .'import, an analysis, a delivery — and retrying is a decision the job\'s own type has to '
                .'allow.',
        )];
    }

    /**
     * @return list<ProductionCheck>
     */
    private function operations(): array
    {
        $checks = $this->backupConfiguration();

        try {
            $backups = $this->backups->complete();
        } catch (Throwable) {
            return [...$checks, ProductionCheck::unknown(
                'Backup',
                'freshness',
                'The backup directory could not be read.',
                'Check that backup.destination exists and is readable.',
            )];
        }

        if ($backups === []) {
            return [...$checks, ProductionCheck::warning(
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
            return [...$checks, ProductionCheck::unknown('Backup', 'freshness', 'The age of the newest backup could not be read.')];
        }

        $age = time() - $createdAt;

        return [...$checks, $age > 7 * 86400
            ? ProductionCheck::warning(
                'Backup',
                'freshness',
                sprintf('The newest backup is %d day(s) old.', intdiv($age, 86400)),
                'Schedule `sanitube:backup`. A week-old backup is a week of work to re-do.',
            )
            : ProductionCheck::ready('Backup', 'freshness', 'A recent backup exists.')];
    }

    /**
     * Whether the next backup can run at all.
     *
     * Freshness answers "when was the last one"; this answers "will there be
     * another". DEP-005 refuses an include path that resolves outside the
     * installation, that is the application root, or that touches the backup
     * destination — and it refuses *at backup time*, which on a shared account
     * means a cron line that fails quietly at three in the morning. The
     * operator's first sign is the freshness warning a week later, by which
     * point they have a week of work to re-do.
     *
     * Asked here because this is the command somebody runs when they want to
     * know what is wrong before it costs them something. It resolves paths and
     * writes nothing, which keeps this class read-only.
     *
     * A warning rather than a blocker: a broken backup configuration does not
     * stop the platform serving, and the doctor's blockers are the things that
     * do. It is the loudest warning here all the same — every other backup
     * problem is about one missed run, and this one is about all of them.
     *
     * @return list<ProductionCheck>
     */
    private function backupConfiguration(): array
    {
        /** @var list<string> $configured */
        $configured = (array) $this->config->get('backup.include_paths', []);

        try {
            $this->contents->directories($configured);
        } catch (BackupException $exception) {
            return [ProductionCheck::warning(
                'Backup',
                'include_paths',
                // The refusal's own sentence, which names the path and says
                // why. It quotes a configured *path*, never a value from a
                // credential-shaped setting.
                $exception->getMessage(),
                'Every backup will fail until this is corrected. See the include-path rules in '
                    .'docs/deployment/backup.md.',
            )];
        }

        return [];
    }

    /**
     * Whether acoustic duplicate detection covers the library it is supposed
     * to cover.
     *
     * **The one gap in this platform that produces no error anywhere.** A
     * fingerprint is taken when a candidate appears, and only then. An
     * installation that imported its catalogue on a host with no Chromaprint
     * has none — correctly, because an absent tool is a supported
     * configuration. When that host later gains the capability, nothing goes
     * back over what is already there: acoustic comparison silently covers
     * none of the library, for ever, and every screen reports exactly what it
     * did before.
     *
     * Never a blocker. Duplicate detection by checksum keeps working, and an
     * installation that has deliberately never fingerprinted anything is not
     * misconfigured — it is a shared account doing what it can.
     *
     * @return list<ProductionCheck>
     */
    private function deduplication(): array
    {
        try {
            $fingerprinter = app(FingerprintAsset::class);

            if (! $fingerprinter->isAvailable()) {
                // A fact about the host, not a fault in it. Said anyway,
                // because "duplicates are checked" and "duplicates are checked
                // by content hash only" are different promises and an operator
                // reviewing a duplicate queue is entitled to know which one
                // they are being given.
                return [ProductionCheck::ready(
                    'Deduplication',
                    'acoustic_coverage',
                    'This host cannot fingerprint. Duplicates are found by checksum only.',
                )];
            }

            $outstanding = Asset::query()
                ->whereIn('status', [AssetStatus::Stored->value, AssetStatus::Verified->value])
                ->whereNull('trashed_at')
                ->get()
                ->filter(static fn (Asset $asset): bool => $fingerprinter->needs($asset))
                ->count();
        } catch (Throwable) {
            return [ProductionCheck::unknown(
                'Deduplication',
                'acoustic_coverage',
                'Whether the library has been fingerprinted could not be determined.',
            )];
        }

        if ($outstanding === 0) {
            return [ProductionCheck::ready(
                'Deduplication',
                'acoustic_coverage',
                'Every stored master has been fingerprinted by the tool this host has.',
            )];
        }

        return [ProductionCheck::warning(
            'Deduplication',
            'acoustic_coverage',
            sprintf('%d stored master(s) have never been fingerprinted by this host\'s tool.', $outstanding),
            'Run `php artisan sanitube:media:fingerprint`. Until it has run, acoustic duplicate '
                .'detection covers none of them and nothing else will say so.',
        )];
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

                continue;
            }

            // Degraded is not optional. It means the thing works and works
            // less well than it could, and every detector that reports it
            // attaches a remediation — which the doctor was dropping on the
            // floor. An installation meaning to use R2 and running on the
            // local disk is told "cannot issue expiring URLs" by
            // `sanitube:health` and nothing at all by the command somebody
            // runs before going live.
            if ($capability->status === CapabilityStatus::Degraded
                && ! array_key_exists($capability->key, self::ANSWERED_ELSEWHERE)) {
                $checks[] = ProductionCheck::warning(
                    'Server',
                    $capability->key,
                    $capability->detail ?? $capability->label.' is degraded.',
                    $capability->remediation,
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
