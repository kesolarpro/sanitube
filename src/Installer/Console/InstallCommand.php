<?php

declare(strict_types=1);

namespace SaniTube\Installer\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use SaniTube\Deployment\Profiles\InstallationProfile;
use SaniTube\Installer\Exceptions\InstallConfigurationException;
use SaniTube\Installer\Services\EnvironmentFile;
use SaniTube\Installer\Services\InstallationJournal;
use SaniTube\Installer\Services\InstallationService;
use SaniTube\Installer\Services\InstallConfiguration;
use SaniTube\Installer\StageResult;

/**
 * `php artisan sanitube:install` — a terminal in front of
 * {@see InstallationService}, and nothing more.
 *
 * The stages, their order and their idempotence live in the service, because
 * INS-002 puts a web form in front of the same thing and a second installer is
 * an installer that gets a fix the other does not.
 *
 * What belongs here is the conversation: asking for the owner's details when
 * nobody supplied them, reading the password without echoing it, and reporting
 * each stage as it finishes so an install that fails at stage six says which
 * six stages ran.
 *
 * `--no-interaction` is a first-class mode, not a degraded one. A deployment
 * script cannot answer a prompt, and an installer that hangs waiting for one
 * is an installer that times out a deploy at three in the morning.
 */
final class InstallCommand extends Command
{
    protected $signature = 'sanitube:install
                            {--owner-name= : The first owner\'s display name}
                            {--owner-email= : The first owner\'s email address}
                            {--skip-owner : Run every stage except creating the first owner}
                            {--profile= : The installation profile — see sanitube:host for a suggestion}
                            {--config= : Read an unattended install configuration from this file (mode 0600)}
                            {--status : Show what previous runs recorded, without running anything}';

    protected $description = 'Prepare this installation: environment, key, database, schema and the first owner';

    private ?InstallationJournal $journal = null;

    private ?InstallConfiguration $configuration = null;

    public function handle(InstallationService $installer, InstallationJournal $journal, EnvironmentFile $environment): int
    {
        $this->journal = $journal;

        if ($this->option('status') === true) {
            return $this->status($journal);
        }

        $profile = $this->profile($journal);

        if ($profile === false) {
            return self::FAILURE;
        }

        $configPath = $this->stringOption('config');

        if ($configPath !== null) {
            try {
                $this->configuration = InstallConfiguration::fromFile($configPath);
            } catch (InstallConfigurationException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
        }

        $this->line('SaniTube installation');
        $this->newLine();

        // Stages in order. Each is idempotent, so a re-run after a failure
        // picks up where the last one stopped rather than starting over.
        $stages = [
            fn (): StageResult => $installer->preflight(),
            fn (): StageResult => $installer->environmentFile(),
            // Between creating .env and generating the key, exactly where the
            // web installer writes what its form collected.
            fn (): StageResult => $this->applyConfiguration($environment),
            fn (): StageResult => $installer->applicationKey(),
            fn (): StageResult => $installer->database(),
            fn (): StageResult => $installer->migrate(),
        ];

        foreach ($stages as $stage) {
            if (! $this->report($stage())) {
                return self::FAILURE;
            }
        }

        $ownerSkipped = $this->option('skip-owner') === true;

        if (! $this->ownerStage($installer)) {
            return self::FAILURE;
        }

        if (! $this->report($installer->verify(ownerRequired: ! $ownerSkipped))) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('SaniTube is installed.');

        foreach ($this->nextSteps($profile) as $step) {
            $this->line($step);
        }

        return self::SUCCESS;
    }

    /**
     * The chosen profile, null when none was given, false when the choice
     * cannot be honoured.
     *
     * No profile is ever chosen *for* the operator: with no --profile the run
     * proceeds exactly as before, under whatever a previous run recorded. A
     * profile this build does not know fails by name with the list;
     * WORKER_ONLY fails because this command installs Core — a database, a
     * schema, an owner — and a worker host wants none of that.
     */
    private function profile(InstallationJournal $journal): InstallationProfile|null|false
    {
        $option = $this->stringOption('profile');

        if ($option === null) {
            return $journal->recordedProfile();
        }

        $profile = InstallationProfile::tryFrom(strtoupper($option));

        if ($profile === null) {
            $this->error(sprintf(
                'Unknown profile [%s]. Known profiles: %s. Run sanitube:host for a suggestion.',
                $option,
                implode(', ', array_map(static fn (InstallationProfile $p): string => $p->value, InstallationProfile::cases())),
            ));

            return false;
        }

        if (! $profile->includesCore()) {
            $this->error(sprintf(
                '[%s] does not install Core, and this command installs Core. See docs/deployment/worker.md '
                .'for setting up a worker host.',
                $profile->value,
            ));

            return false;
        }

        $previous = $journal->recordedProfile();

        if ($previous !== null && $previous !== $profile) {
            // Not refused — machines get reprofiled — but never silent: the
            // services configured for the old profile do not remove themselves.
            $this->warn(sprintf(
                'This installation was previously profiled %s. Recording %s; anything configured for the '
                .'old profile (cron lines, service units) is not undone by this.',
                $previous->value,
                $profile->value,
            ));
        }

        $journal->rememberProfile($profile);

        return $profile;
    }

    private function status(InstallationJournal $journal): int
    {
        if ($journal->isEmpty()) {
            $this->line('No installation has been recorded on this machine.');

            return self::SUCCESS;
        }

        $profile = $journal->recordedProfile();

        $this->line(sprintf('Installation started %s', (string) $journal->startedAt()));
        $this->line(sprintf('Profile: %s', $profile === null ? '(none recorded)' : $profile->value.' — '.$profile->label()));
        $this->newLine();

        foreach ($journal->stages() as $name => $entry) {
            $this->line(sprintf('  %-8s %-18s %s  %s', strtolower($entry['outcome']), $name, $entry['at'], $entry['detail']));
        }

        return self::SUCCESS;
    }

    /**
     * What to do next, in the vocabulary of the profile that was chosen.
     *
     * The generic sentence survives for a run with no profile: guessing one
     * here would contradict everything profile() refuses to guess.
     *
     * @return list<string>
     */
    private function nextSteps(?InstallationProfile $profile): array
    {
        return match ($profile?->queueStrategy()) {
            'scheduled-bursts' => [
                'Sign in, then add the scheduler to cron — every minute:',
                '  * * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1',
                'The queue is worked in short bursts from the scheduler on this profile; no persistent process is needed.',
            ],
            'persistent-worker' => [
                'Sign in, then give the scheduler and the queue a home:',
                '  - scheduler: a cron line or a systemd timer running `php artisan schedule:run` every minute',
                '  - queue: a persistent `php artisan queue:work` under systemd or a supervisor',
            ],
            default => [
                'Sign in, then run a queue worker or add the scheduler to cron so imports and retries happen.',
            ],
        };
    }

    /**
     * The one stage that needs to ask a person something.
     */
    private function ownerStage(InstallationService $installer): bool
    {
        if ($this->option('skip-owner') === true) {
            $this->reportLine(StageResult::skipped('owner', 'Skipped at your request. Run sanitube:user:create before signing in.'));

            return true;
        }

        if ($installer->status()->hasActiveOwner === true) {
            $this->reportLine(StageResult::skipped('owner', 'An active owner already exists; no account was created.'));

            return true;
        }

        if ($this->configuration?->ownerEmail() !== null) {
            return $this->ownerFromConfiguration($installer, $this->configuration);
        }

        $name = $this->stringOption('owner-name');
        $email = $this->stringOption('owner-email');

        // Symfony sets this from --no-interaction, which is what a deployment
        // script passes. Asked of the input rather than of the terminal so the
        // flag works the same way everywhere.
        if ($this->option('no-interaction') === true || ! $this->input->isInteractive()) {
            if ($name === null || $email === null) {
                $this->reportLine(StageResult::failed(
                    'owner',
                    'Running without interaction needs --owner-name and --owner-email, or --skip-owner.',
                ));

                return false;
            }

            // No password can be collected without a prompt, and accepting one
            // as an option would put it in the shell history and in every
            // process listing on the machine. The account is created by
            // sanitube:user:create afterwards instead.
            $this->reportLine(StageResult::failed(
                'owner',
                'A password cannot be collected without interaction, and passing one as an option would '
                    .'record it in shell history. Re-run with --skip-owner and use sanitube:user:create.',
            ));

            return false;
        }

        $name ??= (string) $this->ask('Owner name');
        $email ??= (string) $this->ask('Owner email');

        // secret(), never ask(): a password echoed to a terminal is a password
        // in the scrollback, and on a shared host that scrollback is somebody
        // else's too.
        $password = (string) $this->secret('Owner password');

        if ($password !== (string) $this->secret('Confirm password')) {
            $this->reportLine(StageResult::failed('owner', 'The passwords did not match.'));

            return false;
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:12'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->reportLine(StageResult::failed('owner', (string) $message));
            }

            return false;
        }

        return $this->report($installer->createOwner($name, $email, $password));
    }

    /**
     * Write what the config file says into `.env`, the web installer's way.
     *
     * The backup happens first for the same reason the key stage backs up:
     * this rewrites a file that already holds credentials. And nothing here
     * prints a value — the stage names the keys it wrote, never their
     * contents.
     */
    private function applyConfiguration(EnvironmentFile $environment): StageResult
    {
        $config = $this->configuration;

        if ($config === null) {
            return StageResult::skipped('configuration', 'No config file was given; .env is taken as it stands.');
        }

        if ($config->environment === []) {
            return StageResult::skipped('configuration', 'The config file sets no environment keys.');
        }

        if (! $environment->backup()) {
            return StageResult::failed('configuration', 'The .env file could not be backed up, so it was not modified.');
        }

        if (! $environment->setMany($config->environment)) {
            return StageResult::failed('configuration', 'The .env file could not be written.');
        }

        $this->reconnect($config->environment);

        return StageResult::done('configuration', 'Wrote '.implode(', ', array_keys($config->environment)).'.');
    }

    /**
     * Point the default connection at what was just written — the same move,
     * for the same reason, as the web installer: the connection was resolved
     * from the old values when this process booted, and without this the
     * database and migration stages would report on what `.env` used to say.
     * Purged only when something actually changed, so a re-run does not throw
     * away the handle the previous stages just proved good.
     *
     * @param  array<string, string>  $written
     */
    private function reconnect(array $written): void
    {
        $connection = $written['DB_CONNECTION'] ?? (string) config('database.default');

        $settings = ['database.default' => $connection];

        foreach ([
            'DB_HOST' => 'host',
            'DB_PORT' => 'port',
            'DB_DATABASE' => 'database',
            'DB_USERNAME' => 'username',
            'DB_PASSWORD' => 'password',
        ] as $env => $key) {
            if (array_key_exists($env, $written)) {
                $settings['database.connections.'.$connection.'.'.$key] = $written[$env];
            }
        }

        $changed = false;

        foreach ($settings as $key => $value) {
            if ((string) config($key) !== $value) {
                $changed = true;
            }

            config([$key => $value]);
        }

        if ($changed) {
            $this->laravel->make('db')->purge($connection);
        }
    }

    /**
     * The owner as the config file describes them.
     *
     * The password may come from the file (mode-checked to 0600 before a
     * byte was read) or from SANITUBE_OWNER_PASSWORD in the process
     * environment — the two places a secret can live without landing in argv
     * or shell history. Absent from both, the stage fails naming both,
     * because an unattended run has nobody to ask.
     */
    private function ownerFromConfiguration(InstallationService $installer, InstallConfiguration $config): bool
    {
        $name = $config->ownerName();
        $email = (string) $config->ownerEmail();
        $password = $config->ownerPassword() ?? $this->passwordFromEnvironment();

        if ($name === null) {
            $this->reportLine(StageResult::failed('owner', 'The config file sets OWNER_EMAIL but not OWNER_NAME.'));

            return false;
        }

        if ($password === null) {
            $this->reportLine(StageResult::failed(
                'owner',
                'No owner password was provided. Set OWNER_PASSWORD in the config file (mode 0600) or '
                    .'SANITUBE_OWNER_PASSWORD in the environment of this process.',
            ));

            return false;
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:12'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->reportLine(StageResult::failed('owner', (string) $message));
            }

            return false;
        }

        return $this->report($installer->createOwner($name, $email, $password));
    }

    private function passwordFromEnvironment(): ?string
    {
        $value = getenv('SANITUBE_OWNER_PASSWORD');

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * @return bool whether the run may continue
     */
    private function report(StageResult $result): bool
    {
        $this->reportLine($result);

        return ! $result->hasFailed();
    }

    private function reportLine(StageResult $result): void
    {
        // Every outcome reaches the journal, skips and failures included: a
        // resumed install is debugged from what actually happened, and a
        // journal of successes only would say nothing about the stage that
        // matters.
        $this->journal?->record($result);

        $label = str_pad($result->stage, 18);

        match ($result->outcome) {
            StageResult::DONE => $this->line(sprintf('  <fg=green>done</>    %s %s', $label, $result->detail)),
            StageResult::SKIPPED => $this->line(sprintf('  <fg=yellow>skipped</> %s %s', $label, $result->detail)),
            default => $this->line(sprintf('  <fg=red>failed</>  %s %s', $label, $result->detail)),
        };
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
