<?php

declare(strict_types=1);

namespace SaniTube\Deployment\Console;

use Illuminate\Console\Command;
use SaniTube\Deployment\Exceptions\BackupException;
use SaniTube\Deployment\Services\BackupRepository;
use SaniTube\Deployment\Services\CreateBackup;

/**
 * `php artisan sanitube:backup`.
 *
 * Non-interactive by construction — this runs from cron on every installation
 * that has one — and it prints what was *left out* as loudly as what was
 * included. A backup an operator misunderstands is a backup they will discover
 * the shape of during a restore.
 */
final class BackupCommand extends Command
{
    protected $signature = 'sanitube:backup
                            {--label= : A word to append to the directory name}
                            {--keep= : Override how many complete backups to keep}
                            {--no-prune : Keep every backup, whatever the retention says}';

    protected $description = 'Back up the database and the files a restore needs';

    public function handle(CreateBackup $backup, BackupRepository $repository): int
    {
        try {
            $result = $backup->handle($this->stringOption('label'));
        } catch (BackupException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $manifest = $result['manifest'];

        $this->info('Backed up to '.$result['path']);
        $this->line(sprintf('  %d entries, database driver %s', count($manifest->entries), $manifest->databaseDriver));

        // The exclusions are the part people get wrong, so they are printed
        // every time rather than hidden behind a verbosity flag.
        $this->newLine();
        $this->line('Not included:');

        foreach ($manifest->excluded as $what => $why) {
            $this->line(sprintf('  %-28s %s', $what, $why));
        }

        if ($this->option('no-prune') === true) {
            return self::SUCCESS;
        }

        $keep = $this->stringOption('keep');
        $removed = $repository->prune($keep === null ? null : (int) $keep);

        if ($removed !== []) {
            $this->newLine();
            $this->line(sprintf('Pruned %d older backup(s).', count($removed)));
        }

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
