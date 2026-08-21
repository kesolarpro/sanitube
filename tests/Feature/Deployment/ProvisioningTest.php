<?php

declare(strict_types=1);

namespace Tests\Feature\Deployment;

use PHPUnit\Framework\Attributes\Test;
use SaniTube\Deployment\Exceptions\ProvisionException;
use SaniTube\Deployment\Provisioning\ProvisionInputs;
use SaniTube\Deployment\Provisioning\ServiceFileGenerator;
use Tests\TestCase;

/**
 * Generated service files are privileged text, and the door is guarded.
 *
 * DEP-010. systemd runs what a unit says as the user it names; nginx serves
 * what a server block points at. A value that smuggles a newline into a unit
 * is a second directive, so inputs are refused — never escaped — and the
 * refusal message never echoes the refused value, which is by definition the
 * string a terminal should not be handed back.
 */
final class ProvisioningTest extends TestCase
{
    // ---------------------------------------------------------- refusals

    #[Test]
    public function a_newline_in_any_input_is_refused_not_escaped(): void
    {
        foreach ([
            'instance' => ['instance' => "a\nExecStart=/bin/evil"],
            'user' => ['user' => "root\nExecStart=/bin/evil"],
            'group' => ['group' => "root\nExecStart=/bin/evil"],
            'php binary' => ['phpBinary' => "/usr/bin/php\nExecStart=/bin/evil"],
            'application path' => ['applicationPath' => "/srv/app\n/etc"],
            'php-fpm socket' => ['phpFpmSocket' => "/run/php.sock\nlocation /evil {}"],
            'upload size' => ['uploadMax' => '512m; include /etc/passwd'],
        ] as $label => $override) {
            try {
                $this->inputs($override);
                $this->fail(sprintf('A newline in the %s was accepted.', $label));
            } catch (ProvisionException $exception) {
                $this->assertSame('PROVISION_INVALID_INPUT', $exception->reason);
                $this->assertStringNotContainsString('evil', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function a_domain_that_could_rewrite_a_server_block_is_refused(): void
    {
        foreach (['a b.example', 'ex;ample.com', 'brace{s}.com', 'no_underscores.example', 'trailingdot.example.'] as $domain) {
            try {
                $this->inputs(['domain' => $domain]);
                $this->fail(sprintf('[%s] was accepted as a domain.', $domain));
            } catch (ProvisionException $exception) {
                $this->assertSame('PROVISION_INVALID_INPUT', $exception->reason);
            }
        }
    }

    #[Test]
    public function honest_values_pass_the_same_door(): void
    {
        $inputs = $this->inputs([
            'domain' => 'music.label-name.example',
            'phpFpmSocket' => '/run/php/php8.3-fpm.sock',
        ]);

        $this->assertSame('music.label-name.example', $inputs->domain);
    }

    // -------------------------------------------------------- generation

    #[Test]
    public function the_queue_unit_is_a_template_so_concurrency_is_never_baked_in(): void
    {
        $generator = new ServiceFileGenerator($this->inputs(['instance' => 'studio-two']));

        $this->assertSame('studio-two-queue@.service', $generator->queueServiceName());

        $unit = $generator->queueService();
        $this->assertStringContainsString('Description=studio-two queue worker %i', $unit);
        $this->assertStringContainsString('Restart=on-failure', $unit);
        $this->assertStringContainsString('TimeoutStopSec=', $unit);
        $this->assertStringContainsString('--memory=', $unit);
    }

    #[Test]
    public function two_instances_on_one_vps_cannot_collide(): void
    {
        $one = new ServiceFileGenerator($this->inputs(['instance' => 'label-one']));
        $two = new ServiceFileGenerator($this->inputs(['instance' => 'label-two']));

        $names = [
            $one->queueServiceName(), $one->schedulerServiceName(), $one->schedulerTimerName(), $one->nginxServerName(),
            $two->queueServiceName(), $two->schedulerServiceName(), $two->schedulerTimerName(), $two->nginxServerName(),
        ];

        $this->assertSame($names, array_unique($names));
    }

    #[Test]
    public function the_nginx_block_denies_everything_dotted_and_stays_http(): void
    {
        $generator = new ServiceFileGenerator($this->inputs([
            'domain' => 'label.example',
            'phpFpmSocket' => '/run/php/php-fpm.sock',
        ]));

        $block = $generator->nginxServer();

        $this->assertStringContainsString('server_name label.example;', $block);
        $this->assertStringContainsString('location ~ /\.', $block);
        $this->assertStringContainsString('deny all;', $block);
        // TLS is certbot's to add to a real certificate, not ours to guess.
        $this->assertStringNotContainsString('443', $block);
        $this->assertStringNotContainsString('ssl_certificate', $block);
    }

    #[Test]
    public function an_nginx_block_without_its_facts_is_a_named_refusal_not_a_placeholder(): void
    {
        $generator = new ServiceFileGenerator($this->inputs());

        try {
            $generator->nginxServer();
            $this->fail('A server block was generated with no domain.');
        } catch (ProvisionException $exception) {
            $this->assertSame('PROVISION_MISSING_INPUT', $exception->reason);
        }
    }

    #[Test]
    public function the_scheduler_timer_ticks_every_minute_and_does_not_replay_downtime(): void
    {
        $generator = new ServiceFileGenerator($this->inputs());

        $this->assertStringContainsString('OnCalendar=*-*-* *:*:00', $generator->schedulerTimer());
        $this->assertStringContainsString('Persistent=false', $generator->schedulerTimer());
        $this->assertStringContainsString('schedule:run', $generator->schedulerService());
    }

    #[Test]
    public function nothing_generated_hardcodes_an_account_a_path_or_a_domain(): void
    {
        // The classic sin this exists to prevent: www-data baked into a unit.
        // Everything varying arrives through inputs; the literals that remain
        // are systemd/nginx vocabulary.
        $generator = new ServiceFileGenerator($this->inputs(['user' => 'melody', 'group' => 'melody']));

        $everything = $generator->queueService().$generator->schedulerService().$generator->schedulerTimer();

        $this->assertStringNotContainsString('www-data', $everything);
        $this->assertStringContainsString('User=melody', $everything);
    }

    // ------------------------------------------------------------ command

    #[Test]
    public function the_command_writes_the_files_it_promises_where_it_is_told(): void
    {
        $into = storage_path('framework/testing/provision-'.uniqid());

        $this->artisan('sanitube:provision', [
            '--domain' => 'label.example',
            '--socket' => '/run/php/php-fpm.sock',
            '--user' => 'melody',
            '--into' => $into,
        ])->assertSuccessful();

        $instance = (string) config('sanitube.instance');

        $this->assertFileExists($into.'/'.$instance.'-queue@.service');
        $this->assertFileExists($into.'/'.$instance.'-scheduler.timer');
        $this->assertFileExists($into.'/'.$instance.'.conf');
        $this->assertFileExists($into.'/crontab.line');

        $cron = (string) file_get_contents($into.'/crontab.line');
        $this->assertStringContainsString('schedule:run', $cron);
        $this->assertStringContainsString(base_path(), $cron);

        foreach (glob($into.'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($into);
    }

    #[Test]
    public function a_poisoned_option_fails_the_command_by_label_never_by_value(): void
    {
        $this->artisan('sanitube:provision', ['--user' => "root\nExecStart=/bin/evil"])
            ->expectsOutputToContain('user is not usable')
            ->assertFailed();
    }

    #[Test]
    public function without_a_destination_the_command_prints_instead_of_writing(): void
    {
        $this->artisan('sanitube:provision')
            ->expectsOutputToContain('schedule:run >> /dev/null')
            ->assertSuccessful();
    }

    // ----------------------------------------------------------- fixtures

    /**
     * @param  array<string, string|null>  $overrides
     */
    private function inputs(array $overrides = []): ProvisionInputs
    {
        $values = array_merge([
            'instance' => 'sanitube',
            'applicationPath' => '/srv/sanitube',
            'phpBinary' => '/usr/bin/php',
            'user' => 'sanitube',
            'group' => 'sanitube',
            'domain' => null,
            'phpFpmSocket' => null,
            'uploadMax' => '512m',
        ], $overrides);

        return new ProvisionInputs(
            instance: (string) $values['instance'],
            applicationPath: (string) $values['applicationPath'],
            phpBinary: (string) $values['phpBinary'],
            user: (string) $values['user'],
            group: (string) $values['group'],
            domain: $values['domain'],
            phpFpmSocket: $values['phpFpmSocket'],
            uploadMax: (string) $values['uploadMax'],
        );
    }
}
