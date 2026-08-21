<?php

declare(strict_types=1);

namespace SaniTube\Deployment\Console;

use Illuminate\Console\Command;
use SaniTube\Deployment\Smoke\SmokeCheck;
use SaniTube\Deployment\Smoke\SmokeTestRunner;

/**
 * The last step of a deploy: prove the installation over real HTTP.
 *
 * GET only, nothing authenticated, bounded timeouts. The URL defaults to
 * APP_URL because that is the address the installation claims to live at —
 * and smoking a different address than the one in configuration would prove
 * a server, not this installation.
 */
final class SmokeCommand extends Command
{
    protected $signature = 'sanitube:smoke
                            {--url= : Base URL to test (default: APP_URL)}
                            {--json : Output the checks as JSON}';

    protected $description = 'Run HTTP smoke tests against the deployed installation';

    public function handle(SmokeTestRunner $runner): int
    {
        $url = $this->stringOption('url') ?? (string) config('app.url');

        if (trim($url) === '' || ! str_starts_with($url, 'http')) {
            $this->error('No usable base URL: pass --url or set APP_URL.');

            return self::FAILURE;
        }

        $checks = $runner->run($url);

        if ($this->option('json')) {
            $this->line((string) json_encode(
                array_map(static fn (SmokeCheck $check): array => $check->toArray(), $checks),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));
        } else {
            foreach ($checks as $check) {
                $this->line(sprintf(
                    '  %s %-28s %s',
                    match ($check->outcome) {
                        SmokeCheck::PASSED => '<fg=green>passed</> ',
                        SmokeCheck::SKIPPED => '<fg=yellow>skipped</>',
                        default => '<fg=red>failed</> ',
                    },
                    $check->name,
                    $check->detail,
                ));
            }
        }

        foreach ($checks as $check) {
            if ($check->hasFailed()) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
