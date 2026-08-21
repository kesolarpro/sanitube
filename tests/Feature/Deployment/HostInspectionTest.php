<?php

declare(strict_types=1);

namespace Tests\Feature\Deployment;

use PHPUnit\Framework\Attributes\Test;
use SaniTube\Deployment\Host\HostFacts;
use SaniTube\Deployment\Host\HostInspector;
use SaniTube\Deployment\Host\HostProbe;
use SaniTube\Deployment\Host\RealHostProbe;
use SaniTube\Deployment\Profiles\InstallationProfile;
use SaniTube\Deployment\Profiles\ProfileAdvice;
use SaniTube\Deployment\Profiles\ProfileAdvisor;
use Tests\Support\Deployment\FakeHostProbe;
use Tests\TestCase;

/**
 * The machine is read, never assumed.
 *
 * DEP-007. The installer's first act on any host is to ask what is actually
 * there — cPanel or root VPS, systemd or cron, Composer or nothing — and
 * every later decision keys off those answers. These tests hold the reading
 * itself: each fake below is a machine shape SaniTube genuinely targets, and
 * the assertions are what the installer will rely on being true about it.
 *
 * Detection executes nothing. That is held here too, structurally: the fake
 * probe cannot run a process, so an inspector that tried would fail every
 * test in this file at once.
 */
final class HostInspectionTest extends TestCase
{
    // -------------------------------------------------------------- facts

    #[Test]
    public function a_debian_root_vps_is_read_as_what_it_is(): void
    {
        $facts = $this->inspect(new FakeHostProbe(
            directories: ['/run/systemd/system'],
            files: ['/etc/os-release' => "ID=debian\nVERSION_ID=\"12\"\nPRETTY_NAME=\"Debian GNU/Linux 12 (bookworm)\"\n"],
            binaries: [
                'apt-get' => '/usr/bin/apt-get',
                'crontab' => '/usr/bin/crontab',
                'nginx' => '/usr/sbin/nginx',
                'composer' => '/usr/local/bin/composer',
                'mysql' => '/usr/bin/mysql',
            ],
            effectiveUserId: 0,
        ));

        $this->assertSame('debian', $facts->distributionId);
        $this->assertSame('12', $facts->distributionVersion);
        $this->assertSame('Debian GNU/Linux 12 (bookworm)', $facts->distributionName);
        $this->assertTrue($facts->isRoot);
        $this->assertFalse($facts->isCpanel());
        $this->assertTrue($facts->hasSystemd);
        $this->assertSame('apt-get', $facts->packageManager);
        $this->assertSame(['nginx'], $facts->webServers);
        $this->assertSame(['mysql'], $facts->databaseClients);
    }

    #[Test]
    public function cpanel_is_identified_by_its_own_installation_roots(): void
    {
        $facts = $this->inspect(new FakeHostProbe(
            directories: ['/usr/local/cpanel', '/var/cpanel'],
            binaries: ['crontab' => '/usr/bin/crontab'],
        ));

        $this->assertTrue($facts->isCpanel());
        $this->assertSame(['/usr/local/cpanel', '/var/cpanel'], $facts->cpanelEvidence);
    }

    #[Test]
    public function dnf_outranks_yum_because_el_ships_both_names(): void
    {
        $facts = $this->inspect(new FakeHostProbe(binaries: [
            'yum' => '/usr/bin/yum',
            'dnf' => '/usr/bin/dnf',
        ]));

        $this->assertSame('dnf', $facts->packageManager);
    }

    #[Test]
    public function systemd_means_running_not_merely_installed(): void
    {
        // A container image frequently carries the systemctl binary while
        // nothing manages services at all. The marker directory is created by
        // a *running* systemd, and it is the only evidence accepted.
        $facts = $this->inspect(new FakeHostProbe(
            binaries: ['systemctl' => '/usr/bin/systemctl'],
        ));

        $this->assertFalse($facts->hasSystemd);
    }

    #[Test]
    public function the_os_release_fallback_path_is_honoured(): void
    {
        $facts = $this->inspect(new FakeHostProbe(
            files: ['/usr/lib/os-release' => "ID=almalinux\nVERSION_ID=\"9.4\"\n"],
        ));

        $this->assertSame('almalinux', $facts->distributionId);
        $this->assertSame('9.4', $facts->distributionVersion);
    }

    #[Test]
    public function a_machine_that_cannot_say_who_runs_it_says_so(): void
    {
        // No posix extension: "could not ask" must stay distinct from "not
        // root" — the advisor treats the two differently, and collapsing them
        // here would make its distinction unreachable.
        $facts = $this->inspect(new FakeHostProbe(effectiveUserId: null));

        $this->assertNull($facts->isRoot);
    }

    #[Test]
    public function open_basedir_and_disabled_proc_open_are_read_as_restrictions(): void
    {
        $facts = $this->inspect(new FakeHostProbe(
            ini: ['open_basedir' => '/home/account:/tmp'],
            usableFunctions: [],
        ));

        $this->assertSame('/home/account:/tmp', $facts->openBasedir);
        $this->assertFalse($facts->canRunProcesses);
    }

    #[Test]
    public function a_loaded_nvidia_driver_is_the_fact_not_the_binary(): void
    {
        // The nvidia-smi binary proves a package was installed; the kernel
        // marker proves the driver is loaded — the fact a generation worker
        // actually needs.
        $installedOnly = $this->inspect(new FakeHostProbe(
            binaries: ['nvidia-smi' => '/usr/bin/nvidia-smi'],
        ));

        $this->assertFalse($installedOnly->nvidiaDriver);
        $this->assertSame('/usr/bin/nvidia-smi', $installedOnly->nvidiaSmi);

        $loaded = $this->inspect(new FakeHostProbe(
            directories: ['/proc/driver/nvidia'],
        ));

        $this->assertTrue($loaded->nvidiaDriver);
    }

    // ------------------------------------------------------------- advice

    #[Test]
    public function cpanel_evidence_outranks_everything_a_vps_would_have(): void
    {
        // A cPanel server has root, systemd and yum — for cPanel's own use.
        // Suggesting VPS_CORE there would have the installer writing system
        // services on a machine whose owner is the hosting company.
        $advice = $this->advise(new FakeHostProbe(
            directories: ['/usr/local/cpanel', '/run/systemd/system'],
            binaries: ['yum' => '/usr/bin/yum', 'crontab' => '/usr/bin/crontab'],
            effectiveUserId: 0,
        ));

        $this->assertSame(InstallationProfile::Cpanel, $advice->suggestion);
        $this->assertStringContainsString('/usr/local/cpanel', implode(' ', $advice->reasons));
    }

    #[Test]
    public function root_systemd_and_a_package_manager_suggest_a_vps_core(): void
    {
        $advice = $this->advise(new FakeHostProbe(
            directories: ['/run/systemd/system'],
            files: ['/etc/os-release' => "ID=ubuntu\nPRETTY_NAME=\"Ubuntu 24.04 LTS\"\n"],
            binaries: [
                'apt-get' => '/usr/bin/apt-get',
                'crontab' => '/usr/bin/crontab',
                'composer' => '/usr/local/bin/composer',
            ],
            effectiveUserId: 0,
        ));

        $this->assertSame(InstallationProfile::VpsCore, $advice->suggestion);
        $this->assertSame([], $advice->cautions);
    }

    #[Test]
    public function what_is_missing_for_a_vps_is_named_not_summarised(): void
    {
        $advice = $this->advise(new FakeHostProbe(
            binaries: ['crontab' => '/usr/bin/crontab', 'composer' => '/usr/local/bin/composer'],
            effectiveUserId: 1000,
        ));

        $this->assertSame(InstallationProfile::CoreOnlyGeneric, $advice->suggestion);

        $reasons = implode(' ', $advice->reasons);
        $this->assertStringContainsString('root', $reasons);
        $this->assertStringContainsString('systemd', $reasons);
        $this->assertStringContainsString('package manager', $reasons);
    }

    #[Test]
    public function an_unanswerable_root_question_is_reported_as_unanswerable(): void
    {
        $advice = $this->advise(new FakeHostProbe(
            directories: ['/run/systemd/system'],
            binaries: ['apt-get' => '/usr/bin/apt-get', 'crontab' => '/usr/bin/crontab', 'composer' => '/usr/local/bin/composer'],
            effectiveUserId: null,
        ));

        $this->assertSame(InstallationProfile::CoreOnlyGeneric, $advice->suggestion);
        $this->assertStringContainsString('undeterminable', implode(' ', $advice->reasons));
    }

    #[Test]
    public function a_non_linux_host_gets_the_generic_profile_with_the_reason(): void
    {
        $advice = $this->advise(new FakeHostProbe(osFamily: 'Darwin'));

        $this->assertSame(InstallationProfile::CoreOnlyGeneric, $advice->suggestion);
        $this->assertStringContainsString('Darwin', implode(' ', $advice->reasons));
    }

    #[Test]
    public function the_two_choice_profiles_are_always_offered_never_suggested(): void
    {
        foreach ([
            new FakeHostProbe(directories: ['/usr/local/cpanel']),
            new FakeHostProbe(
                directories: ['/run/systemd/system'],
                binaries: ['apt-get' => '/usr/bin/apt-get', 'crontab' => '/usr/bin/crontab', 'composer' => '/usr/local/bin/composer'],
                effectiveUserId: 0,
            ),
            new FakeHostProbe(osFamily: 'BSD'),
        ] as $machine) {
            $advice = $this->advise($machine);

            $this->assertNotSame(InstallationProfile::VpsCoreAndWorker, $advice->suggestion);
            $this->assertNotSame(InstallationProfile::WorkerOnly, $advice->suggestion);

            $alternatives = implode(' ', $advice->alternatives);
            $this->assertStringContainsString(InstallationProfile::VpsCoreAndWorker->value, $alternatives);
            $this->assertStringContainsString(InstallationProfile::WorkerOnly->value, $alternatives);
        }
    }

    #[Test]
    public function a_host_with_no_scheduler_home_is_cautioned_loudly(): void
    {
        $advice = $this->advise(new FakeHostProbe(
            binaries: ['composer' => '/usr/local/bin/composer'],
        ));

        $this->assertStringContainsString('scheduler', implode(' ', $advice->cautions));
    }

    #[Test]
    public function missing_composer_is_a_caution_on_every_shape(): void
    {
        $advice = $this->advise(new FakeHostProbe(
            directories: ['/usr/local/cpanel'],
            binaries: ['crontab' => '/usr/bin/crontab'],
        ));

        $this->assertStringContainsString('Composer', implode(' ', $advice->cautions));
    }

    #[Test]
    public function the_worker_alternative_states_the_gpu_fact_both_ways(): void
    {
        $without = $this->advise(new FakeHostProbe(osFamily: 'Darwin'));
        $this->assertStringContainsString('no NVIDIA driver is loaded here', implode(' ', $without->alternatives));

        $with = $this->advise(new FakeHostProbe(osFamily: 'Darwin', directories: ['/proc/driver/nvidia']));
        $this->assertStringContainsString('an NVIDIA driver is loaded', implode(' ', $with->alternatives));
    }

    // ------------------------------------------------------------ command

    #[Test]
    public function the_command_prints_the_facts_and_argues_for_its_suggestion(): void
    {
        $this->swap(HostProbe::class, new FakeHostProbe(
            directories: ['/usr/local/cpanel'],
            binaries: ['crontab' => '/usr/bin/crontab', 'composer' => '/usr/local/bin/composer'],
        ));

        $this->artisan('sanitube:host')
            ->expectsOutputToContain('CPANEL')
            ->expectsOutputToContain('cPanel is installed on this server')
            ->expectsOutputToContain('MultiPHP')
            ->assertExitCode(0);
    }

    #[Test]
    public function the_json_shape_carries_both_halves(): void
    {
        $this->swap(HostProbe::class, new FakeHostProbe(
            directories: ['/run/systemd/system'],
            files: ['/etc/os-release' => "ID=rocky\nVERSION_ID=\"9.4\"\n"],
            binaries: ['dnf' => '/usr/bin/dnf', 'crontab' => '/usr/bin/crontab', 'composer' => '/usr/local/bin/composer'],
            effectiveUserId: 0,
        ));

        // One substring per invocation: the whole JSON document is a single
        // console write, and a single write satisfies a single expectation.
        $this->artisan('sanitube:host', ['--json' => true])
            ->expectsOutputToContain('"distribution_id": "rocky"')
            ->assertExitCode(0);

        $this->artisan('sanitube:host', ['--json' => true])
            ->expectsOutputToContain('"suggestion": "VPS_CORE"')
            ->assertExitCode(0);
    }

    #[Test]
    public function the_real_probe_reads_this_very_machine_without_executing_anything(): void
    {
        // Whatever CI runs on, inspection must complete and describe it. The
        // one property assertable everywhere: the PHP version is this PHP's.
        $facts = (new HostInspector(new RealHostProbe))->inspect();

        $this->assertSame(PHP_VERSION, $facts->phpVersion);
        $this->assertContains($facts->osFamily, ['Linux', 'Darwin', 'Windows', 'BSD', 'Solaris', 'Unknown']);
    }

    // ----------------------------------------------------------- fixtures

    private function inspect(HostProbe $probe): HostFacts
    {
        return (new HostInspector($probe))->inspect();
    }

    private function advise(HostProbe $probe): ProfileAdvice
    {
        return (new ProfileAdvisor)->advise($this->inspect($probe));
    }
}
