<?php

declare(strict_types=1);

namespace SaniTube\Deployment\Console;

use Illuminate\Console\Command;
use SaniTube\Deployment\Exceptions\BackupException;
use SaniTube\Deployment\Services\BackupRepository;
use SaniTube\Deployment\Services\RestoreBackup;

/**
 * `php artisan sanitube:restore`.
 *
 * The most destructive command in the platform, and the only one that requires
 * a person to type a word. `--force` exists for automation, and it is spelled
 * out rather than implied by `--no-interaction`: a deploy script that acquired
 * the power to restore by being non-interactive would be one keystroke from
 * replacing a live catalogue.
 *
 * `--verify` runs every check and changes nothing. It is the flag to use on a
 * schedule, because a backup nobody has ever tried to restore is a belief
 * rather than a backup.
 */
final class RestoreCommand extends Command
{
    protected $signature = 'sanitube:restore
                            {backup? : The backup directory. Defaults to the most recent complete one}
                            {--verify : Check the backup and change nothing}
                            {--force : Restore without asking. For automation only}
                            {--allow-schema-drift : Restore even though the migration sets differ}';

    protected $description = 'Restore the database and files from a backup';

    public function handle(RestoreBackup $restore, BackupRepository $repository): int
    {
        $directory = $this->argument('backup');

        if (! is_string($directory) || trim($directory) === '') {
            $complete = $repository->complete();

            if ($complete === []) {
                $this->error('There are no complete backups in '.$repository->destination());

                return self::FAILURE;
            }

            $directory = $complete[0]['path'];
            $this->line('Using the most recent backup: '.$directory);
        }

        $drift = $this->option('allow-schema-drift') === true;

        try {
            $verified = $restore->verify($directory, $drift);
        } catch (BackupException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf(
            '  %d entries, %s, taken %s on %s',
            $verified['entries'],
            $this->humanBytes($verified['bytes']),
            $verified['manifest']->createdAt,
            $verified['manifest']->databaseDriver,
        ));

        if ($verified['drift'] !== []) {
            $this->warn(sprintf('  Schema drift accepted: %d migration(s) differ.', count($verified['drift'])));
        }

        if ($this->option('verify') === true) {
            $this->info('This backup is restorable. Nothing was changed.');

            return self::SUCCESS;
        }

        if (! $this->confirmed()) {
            $this->error('Not confirmed. Nothing was changed.');

            return self::FAILURE;
        }

        try {
            $result = $restore->handle($directory, confirmed: true, allowSchemaDrift: $drift);
        } catch (BackupException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Restored %d tables, %d rows and %d files.',
            $result['tables'],
            $result['rows'],
            $result['files'],
        ));

        $this->line('Rebuild the caches and restart the workers: bin/deploy.sh, or php artisan sanitube:deploy.');

        return self::SUCCESS;
    }

    /**
     * A typed word, or an explicit flag. Never a bare `--no-interaction`.
     */
    private function confirmed(): bool
    {
        if ($this->option('force') === true) {
            return true;
        }

        // Both, and not one or the other. `isInteractive()` alone misses a
        // caller that passed `--no-interaction` to a harness that still has a
        // terminal, and the option alone misses cron.
        if ($this->option('no-interaction') === true || ! $this->input->isInteractive()) {
            $this->error('A restore replaces the live database. Pass --force to do it from a script.');

            return false;
        }

        $this->newLine();
        $this->warn('This replaces the contents of the live database. It cannot be undone.');

        return $this->confirm('Restore this backup?', false);
    }

    private function humanBytes(int $bytes): string
    {
        return $bytes >= 1_048_576
            ? sprintf('%.1f MB', $bytes / 1_048_576)
            : sprintf('%.1f KB', $bytes / 1024);
    }
}
