<?php

declare(strict_types=1);

namespace SaniTube\Installer\Services;

use App\Models\User;
use Illuminate\Contracts\Console\Kernel as Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Installer\InstallationStatus;
use SaniTube\Installer\StageResult;
use SaniTube\Observability\Capabilities\CapabilityRegistry;
use Throwable;

/**
 * Everything an installation needs doing, as separate answerable stages.
 *
 * **The service, not the command, is the installer.** INS-002 will put a web
 * form in front of exactly this, and a web installer that reimplemented the
 * stages would be a second installer — one that gets a fix the other does not.
 * The command is a terminal in front of this class and nothing more.
 *
 * Three properties shape the design.
 *
 * **Every stage is idempotent.** Running the installer twice must be safe,
 * because that is what an operator does when the first run failed at stage
 * six. A stage with nothing to do reports SKIPPED with the reason, which is a
 * different and more useful answer than silently succeeding.
 *
 * **A failure stops the run and says where.** No rollback: undoing a
 * successful migration because a later stage failed would turn a recoverable
 * situation into a lost database. The first five stages stay done, and the
 * next attempt starts from stage six.
 *
 * **No secret is ever returned.** Stages report which key they set, never what
 * they set it to. The caller renders these strings to a terminal or a browser,
 * and both are places a credential should not appear.
 *
 * Nothing here needs root, Docker, Redis or a persistent worker. The shared
 * cPanel account is the baseline, and it is the only environment where the
 * installer's own constraints are interesting.
 */
final readonly class InstallationService
{
    public function __construct(
        private EnvironmentFile $environment,
        private CapabilityRegistry $capabilities,
        private Artisan $artisan,
        private string $examplePath,
    ) {}

    /**
     * What this installation looks like right now.
     *
     * Asked of the installation itself rather than of a marker file: a lock
     * file says somebody once ran the installer, not that the database still
     * has tables or that an owner still exists.
     */
    public function status(): InstallationStatus
    {
        $reachable = $this->databaseReachable();

        return new InstallationStatus(
            hasApplicationKey: $this->environment->has('APP_KEY'),
            databaseReachable: $reachable,
            // Unknown rather than false when the database cannot be reached:
            // "there are no tables" and "I could not look" lead to opposite
            // next steps.
            migrationsRun: $reachable ? $this->migrationsRun() : null,
            hasActiveOwner: $reachable ? $this->hasActiveOwner() : null,
        );
    }

    /**
     * Capabilities preflight deliberately does not block on.
     *
     * Both describe the *installation's history* rather than the machine's
     * ability to run SaniTube, and a preflight check that blocked on either
     * would refuse every first install for a reason that cannot be true yet.
     *
     *   - `scheduler` reports unavailable until a cron entry has fired at
     *     least once. On a machine being installed for the first time it never
     *     has. It is verified *after* installation instead, by the health
     *     command and the operations screen, where a missing heartbeat is a
     *     real finding.
     *   - `database` gets its own stage below, whose message names the DB_
     *     settings and mentions the account prefix shared hosts add. Failing
     *     here instead would hand the operator the less useful of the two
     *     messages.
     *
     * Everything else still blocks. This list is short on purpose and each
     * entry carries its reason, because a preflight that skips checks is a
     * preflight that stops meaning anything.
     *
     * @var list<string>
     */
    private const NOT_PREREQUISITES = ['scheduler', 'database'];

    /**
     * Stage 1 — can this machine run SaniTube at all.
     *
     * Delegated to the capability detectors rather than reimplemented. They
     * already know which PHP extensions matter, which directories must be
     * writable and which of those are merely optional, and a second list here
     * would be the one that goes out of date.
     *
     * Only *blocking* capabilities fail the stage. FFmpeg being absent is a
     * capability SaniTube is designed to run without; refusing to install
     * because a shared host has no ffprobe would refuse to install on the
     * platform's primary target.
     */
    public function preflight(): StageResult
    {
        try {
            $report = $this->capabilities->report();
        } catch (Throwable $exception) {
            return StageResult::failed('preflight', 'The environment could not be inspected: '.$exception->getMessage());
        }

        $blocking = array_values(array_filter(
            $report->blocking(),
            static fn (object $capability): bool => ! in_array((string) $capability->key, self::NOT_PREREQUISITES, true),
        ));

        if ($blocking === []) {
            return StageResult::done('preflight', sprintf('%d capabilities checked, none blocking.', count($report)));
        }

        $names = array_map(static fn (object $capability): string => (string) $capability->label, $blocking);

        return StageResult::failed(
            'preflight',
            'This environment cannot run SaniTube yet: '.implode(', ', $names).'.',
        );
    }

    /**
     * Stage 2 — a configuration file exists.
     */
    public function environmentFile(): StageResult
    {
        if ($this->environment->exists()) {
            return StageResult::skipped('environment', 'A .env file is already present and was left untouched.');
        }

        if (! $this->environment->createFromExample($this->examplePath)) {
            return StageResult::failed(
                'environment',
                'No .env could be created. Check that .env.example exists and that the directory is writable.',
            );
        }

        return StageResult::done('environment', 'Created .env from .env.example.');
    }

    /**
     * Stage 3 — the application key.
     *
     * Never regenerated when one exists, and this is not a convenience.
     * APP_KEY decrypts existing sessions and any encrypted column; replacing
     * it on a running installation logs every user out and makes previously
     * encrypted data unreadable. A re-run of the installer must not do that.
     */
    public function applicationKey(): StageResult
    {
        if ($this->environment->has('APP_KEY')) {
            return StageResult::skipped(
                'application_key',
                'An application key is already set. It was left alone: replacing it would make existing '
                    .'sessions and any encrypted data unreadable.',
            );
        }

        if (! $this->environment->backup()) {
            return StageResult::failed('application_key', 'The .env file could not be backed up, so it was not modified.');
        }

        try {
            $this->artisan->call('key:generate', ['--force' => true]);
        } catch (Throwable $exception) {
            return StageResult::failed('application_key', 'The key could not be generated: '.$exception->getMessage());
        }

        // The key itself is never reported.
        return StageResult::done('application_key', 'Generated a new application key.');
    }

    /**
     * Stage 4 — the database answers.
     */
    public function database(): StageResult
    {
        if (! $this->databaseReachable()) {
            return StageResult::failed(
                'database',
                'The database did not answer. Check the DB_ settings in .env — on shared hosting the '
                    .'host is usually localhost and the database and user names carry an account prefix.',
            );
        }

        return StageResult::done('database', sprintf('Connected using the [%s] connection.', (string) config('database.default')));
    }

    /**
     * Stage 5 — the schema.
     *
     * `--force` because an installer runs unattended as often as not, and
     * Laravel's interactive production guard would hang a deployment script.
     * The destructive flags are absent and stay absent: an installer that can
     * drop tables is one somebody eventually runs on the wrong machine.
     */
    public function migrate(): StageResult
    {
        try {
            $exitCode = $this->artisan->call('migrate', ['--force' => true]);
        } catch (Throwable $exception) {
            return StageResult::failed('migrations', 'The migrations did not complete: '.$exception->getMessage());
        }

        if ($exitCode !== 0) {
            return StageResult::failed('migrations', 'The migrations did not complete. Run `php artisan migrate` to see why.');
        }

        return StageResult::done('migrations', 'The schema is up to date.');
    }

    /**
     * Stage 6 — somebody can sign in.
     *
     * An installation with no owner is unadministrable, which on a
     * single-account platform means unusable. The password arrives already
     * collected by the caller and is hashed by the model's cast; it is not
     * returned, echoed or logged anywhere in this class.
     */
    public function createOwner(string $name, string $email, string $password): StageResult
    {
        if ($this->hasActiveOwner()) {
            return StageResult::skipped('owner', 'An active owner already exists; no account was created.');
        }

        try {
            $user = User::query()->create([
                'name' => trim($name),
                'email' => trim($email),
                'password' => $password,
            ]);

            // role and is_active are not mass-assignable by design (SEC-001).
            $user->forceFill(['role' => UserRole::Owner, 'is_active' => true])->save();
        } catch (Throwable $exception) {
            return StageResult::failed('owner', 'The owner account could not be created: '.$exception->getMessage());
        }

        // The address, never the password.
        return StageResult::done('owner', sprintf('Created the owner account for %s.', trim($email)));
    }

    /**
     * Stage 7 — is it actually installed.
     *
     * Asked again from scratch rather than inferred from the stages having
     * reported success. A stage that believed it succeeded and an
     * installation that works are different claims, and only the second one
     * matters to whoever runs this.
     */
    public function verify(bool $ownerRequired = true): StageResult
    {
        $status = $this->status();

        if ($status->isComplete()) {
            return StageResult::done('verify', 'The installation answers for itself: key, database, schema and an owner.');
        }

        $missing = [];

        foreach ($status->toArray() as $key => $value) {
            if ($key === 'complete') {
                continue;
            }

            // An owner deliberately not created is not a missing piece. A
            // deployment that runs the installer unattended and creates the
            // account separately is a supported flow, and reporting it as a
            // failure would make every such deploy exit non-zero.
            if ($key === 'active_owner' && ! $ownerRequired) {
                continue;
            }

            if ($value !== true) {
                $missing[] = $key;
            }
        }

        if ($missing === []) {
            return StageResult::done(
                'verify',
                'Everything but the owner account is in place. Create one with sanitube:user:create before signing in.',
            );
        }

        return StageResult::failed('verify', 'The installation is not complete: '.implode(', ', $missing).'.');
    }

    /**
     * Whether the configured connection answers.
     *
     * Definitely true or definitely false — a PDO that will not open *is* an
     * unreachable database, not an unanswerable question. The nullable field
     * on {@see InstallationStatus} stays nullable because the two facts that
     * depend on this one genuinely cannot be determined without it.
     */
    private function databaseReachable(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function migrationsRun(): bool
    {
        try {
            // `users` rather than `migrations`: the migrations table exists the
            // moment anything has run, including a partial failure. A table
            // the application actually needs is the honest question.
            return Schema::hasTable('users') && Schema::hasTable('assets');
        } catch (Throwable) {
            return false;
        }
    }

    private function hasActiveOwner(): bool
    {
        try {
            return User::query()
                ->where('role', UserRole::Owner->value)
                ->where('is_active', true)
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }
}
