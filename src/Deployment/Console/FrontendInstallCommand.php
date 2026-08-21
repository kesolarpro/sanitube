<?php

declare(strict_types=1);

namespace SaniTube\Deployment\Console;

use Illuminate\Console\Command;
use SaniTube\Deployment\Exceptions\FrontendBuildException;
use SaniTube\Deployment\Frontend\FrontendBuildInstaller;

/**
 * Install a CI-built frontend bundle without Node ever touching this host.
 *
 * The artifact is `sanitube-frontend-build` from the CI run of the commit
 * this checkout is at — downloaded from the run's page or with
 * `gh run download`, both of which authenticate with the operator's own
 * GitHub credentials. This command deliberately downloads nothing: a token
 * with API scope living in a server's environment for the sake of a deploy
 * step is a bigger door than the step is worth, and the artifact may just as
 * well arrive by scp.
 */
final class FrontendInstallCommand extends Command
{
    protected $signature = 'sanitube:frontend:install
                            {source : The artifact zip, or a directory holding the extracted build}
                            {--sha= : The commit the artifact was built from; checked against this checkout}';

    protected $description = 'Atomically install a CI-built frontend bundle into public/build';

    public function handle(FrontendBuildInstaller $installer): int
    {
        $source = (string) $this->argument('source');
        $sha = $this->stringOption('sha');

        if ($sha === null) {
            $this->warn(
                'No --sha given: the build cannot be checked against this checkout. If it was built '
                    .'from another commit, pages will load hashed assets that do not exist.',
            );
        }

        try {
            $installed = $installer->install($source, $sha);
        } catch (FrontendBuildException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Installed %d manifest entr%s into public/build%s.',
            $installed['entries'],
            $installed['entries'] === 1 ? 'y' : 'ies',
            $installed['sha'] === null ? '' : sprintf(' (commit %s)', $installed['sha']),
        ));

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
