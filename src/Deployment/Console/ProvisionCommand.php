<?php

declare(strict_types=1);

namespace SaniTube\Deployment\Console;

use Illuminate\Console\Command;
use SaniTube\Deployment\Exceptions\ProvisionException;
use SaniTube\Deployment\Provisioning\ProvisionInputs;
use SaniTube\Deployment\Provisioning\ServiceFileGenerator;

/**
 * Render the service files this installation needs — and only render.
 *
 * Generating text and installing text are different privileges. This command
 * holds the first: it prints, or writes into a directory the operator names,
 * files that a root shell (usually the VPS bootstrap) then installs,
 * validates and reloads. A PHP process writing into /etc/systemd itself
 * would need to run as root, and an application that runs as root has made
 * every one of its bugs a root bug.
 *
 * Every default is detected, never assumed: the PHP is the one running this,
 * the user is the one invoking it, the application path is where this is
 * installed, the instance name comes from configuration so two SaniTubes on
 * one VPS generate files that cannot collide.
 */
final class ProvisionCommand extends Command
{
    protected $signature = 'sanitube:provision
                            {--domain= : The DNS name the nginx server block answers for}
                            {--socket= : The php-fpm socket path the nginx block hands PHP to}
                            {--php= : PHP binary for units and cron (default: this one)}
                            {--user= : Service account for units (default: the invoking user)}
                            {--group= : Service group (default: same as user)}
                            {--upload-max= : nginx client_max_body_size (default: from this PHP\'s post_max_size)}
                            {--port= : The HTTP port the nginx block listens on (default 80)}
                            {--into= : Write the files into this directory instead of printing}';

    protected $description = 'Generate the cron line, systemd units and nginx server block for this installation';

    public function handle(): int
    {
        try {
            $generator = new ServiceFileGenerator($this->inputs());
        } catch (ProvisionException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $files = [
            $generator->queueServiceName() => $generator->queueService(),
            $generator->schedulerServiceName() => $generator->schedulerService(),
            $generator->schedulerTimerName() => $generator->schedulerTimer(),
        ];

        // The nginx block only exists when it can be true: without a domain
        // and a socket there is nothing honest to put in server_name or
        // fastcgi_pass, and a placeholder would be a broken file with the
        // installer's name on it.
        if ($this->stringOption('domain') !== null || $this->stringOption('socket') !== null) {
            try {
                $files[$generator->nginxServerName()] = $generator->nginxServer();
            } catch (ProvisionException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
        }

        $into = $this->stringOption('into');

        if ($into === null) {
            $this->line('# Scheduler — add to the service user\'s crontab (or install the timer below):');
            $this->line($generator->cronLine());
            $this->newLine();

            foreach ($files as $name => $content) {
                $this->line(sprintf('# ----- %s', $name));
                $this->line($content);
            }

            return self::SUCCESS;
        }

        if (! is_dir($into) && ! @mkdir($into, 0755, true)) {
            $this->error(sprintf('The directory [%s] could not be created.', $into));

            return self::FAILURE;
        }

        $files['crontab.line'] = $generator->cronLine()."\n";

        foreach ($files as $name => $content) {
            $path = rtrim($into, '/').'/'.$name;

            if (@file_put_contents($path, $content) === false) {
                $this->error(sprintf('Could not write [%s].', $path));

                return self::FAILURE;
            }

            $this->line(sprintf('wrote %s', $path));
        }

        $this->newLine();
        $this->line('Install the units with root, then validate before anything reloads:');
        $this->line('  install -m 0644 *.service *.timer /etc/systemd/system/ && systemd-analyze verify /etc/systemd/system/'.$this->instance().'-*');
        $this->line('  nginx -t before any reload, always.');

        return self::SUCCESS;
    }

    private function inputs(): ProvisionInputs
    {
        return new ProvisionInputs(
            instance: $this->instance(),
            applicationPath: base_path(),
            phpBinary: $this->stringOption('php') ?? PHP_BINARY,
            user: $this->stringOption('user') ?? $this->invokingUser(),
            group: $this->stringOption('group') ?? $this->stringOption('user') ?? $this->invokingUser(),
            domain: $this->stringOption('domain'),
            phpFpmSocket: $this->stringOption('socket'),
            uploadMax: $this->stringOption('upload-max') ?? $this->postMaxSize(),
            httpPort: (int) ($this->stringOption('port') ?? '80'),
        );
    }

    private function instance(): string
    {
        return (string) config('sanitube.instance', 'sanitube');
    }

    /**
     * Whoever is running this, by asking — never `www-data` by assumption.
     * The web server's account differs per distro and per panel, and the
     * queue worker usually should not be it anyway.
     */
    private function invokingUser(): string
    {
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $account = posix_getpwuid(posix_geteuid());

            if (is_array($account) && $account['name'] !== '') {
                return $account['name'];
            }
        }

        $fallback = getenv('USER');

        return is_string($fallback) && $fallback !== '' ? $fallback : 'sanitube';
    }

    /**
     * The body-size ceiling nginx should mirror. PHP already refuses above
     * `post_max_size`; an nginx limit below it would fail uploads PHP would
     * have taken, and one above it only moves the error message.
     */
    private function postMaxSize(): string
    {
        $value = trim((string) ini_get('post_max_size'));

        return preg_match('/^[0-9]{1,5}[kmg]?$/i', $value) === 1 ? strtolower($value) : '512m';
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
