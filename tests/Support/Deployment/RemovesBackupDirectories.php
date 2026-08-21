<?php

declare(strict_types=1);

namespace Tests\Support\Deployment;

/**
 * Deleting a backup directory a test made.
 *
 * Shared rather than copied because the copies disagreed. Both test classes
 * had their own recursive delete built on `glob()`, and `glob()` does not
 * match a leading dot — so the day backups started including hidden files,
 * every one of them left an undeletable directory behind and the whole suite
 * went red on cleanup rather than on anything it was testing.
 *
 * One place, `scandir`, and no second copy to keep in step.
 */
trait RemovesBackupDirectories
{
    protected function rmrf(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        /** @var list<string> $names */
        $names = scandir($directory) ?: [];

        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $entry = $directory.'/'.$name;

            // Links are unlinked, never followed. A test that plants a symlink
            // in a backup directory is testing that pruning refuses to follow
            // it, and a cleanup that followed one would delete the thing the
            // test was protecting.
            if (is_link($entry) || is_file($entry)) {
                unlink($entry);

                continue;
            }

            $this->rmrf($entry);
        }

        rmdir($directory);
    }
}
