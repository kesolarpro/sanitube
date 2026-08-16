<?php

declare(strict_types=1);

namespace SaniTube\Installer\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use SaniTube\Installer\Services\InstallationService;
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
                            {--skip-owner : Run every stage except creating the first owner}';

    protected $description = 'Prepare this installation: environment, key, database, schema and the first owner';

    public function handle(InstallationService $installer): int
    {
        $this->line('SaniTube installation');
        $this->newLine();

        // Stages in order. Each is idempotent, so a re-run after a failure
        // picks up where the last one stopped rather than starting over.
        $stages = [
            fn (): StageResult => $installer->preflight(),
            fn (): StageResult => $installer->environmentFile(),
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
        $this->line('Sign in, then run a queue worker or add the scheduler to cron so imports and retries happen.');

        return self::SUCCESS;
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
     * @return bool whether the run may continue
     */
    private function report(StageResult $result): bool
    {
        $this->reportLine($result);

        return ! $result->hasFailed();
    }

    private function reportLine(StageResult $result): void
    {
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
