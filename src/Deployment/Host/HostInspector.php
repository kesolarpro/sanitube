<?php

declare(strict_types=1);

namespace SaniTube\Deployment\Host;

/**
 * Reads the machine and says what is actually there.
 *
 * SaniTube installs on whatever the operator has — a shared cPanel account, a
 * root VPS, a container — and the installer must never assume which. This is
 * where "never assume" is implemented: one pass of file reads and PATH
 * lookups, no process ever executed, every conclusion derived from evidence a
 * test can fake through {@see HostProbe}.
 *
 * The path and binary names below are *fingerprints of the outside world* —
 * where cPanel installs itself, what Debian calls its package manager — not
 * configuration of this installation. That is why they may be literals when a
 * domain or a bucket name never may: they describe systems SaniTube does not
 * own and the operator cannot rename.
 */
final readonly class HostInspector
{
    /**
     * Directories whose existence identifies a cPanel server. Both are cPanel's
     * own installation roots; an account on such a server cannot remove them.
     */
    private const CPANEL_MARKERS = ['/usr/local/cpanel', '/var/cpanel'];

    /**
     * The systemd runtime marker directory — the mechanism systemd itself
     * documents (sd_booted(3)) for "is systemd running", as opposed to merely
     * installed. Containers with systemd absent lack it even when the
     * `systemctl` binary is on disk.
     */
    private const SYSTEMD_MARKER = '/run/systemd/system';

    /**
     * os-release(5): /etc first, the /usr/lib fallback second.
     */
    private const OS_RELEASE_PATHS = ['/etc/os-release', '/usr/lib/os-release'];

    /**
     * Order matters: `dnf` before `yum` because EL systems ship both names and
     * `yum` is the shim; whichever appears first is the one to speak to.
     */
    private const PACKAGE_MANAGERS = ['apt-get', 'dnf', 'yum', 'zypper', 'apk'];

    private const WEB_SERVERS = ['nginx', 'apache2', 'httpd', 'litespeed', 'openlitespeed'];

    private const DATABASE_CLIENTS = ['mysql', 'mariadb'];

    public function __construct(private HostProbe $probe) {}

    public function inspect(): HostFacts
    {
        $release = $this->osRelease();
        $userId = $this->probe->effectiveUserId();

        return new HostFacts(
            osFamily: $this->probe->osFamily(),
            distributionId: $release['ID'] ?? null,
            distributionVersion: $release['VERSION_ID'] ?? null,
            distributionName: $release['PRETTY_NAME'] ?? null,
            // Null, not false, when posix is absent: "we could not ask" and
            // "not root" lead to different advice, and collapsing them would
            // tell a root operator on a posix-less build to expect failures
            // they will not have.
            isRoot: $userId === null ? null : $userId === 0,
            cpanelEvidence: $this->existing(self::CPANEL_MARKERS),
            hasSystemd: $this->probe->isDirectory(self::SYSTEMD_MARKER),
            cron: $this->probe->binary('crontab'),
            packageManager: $this->firstBinary(self::PACKAGE_MANAGERS),
            webServers: $this->foundBinaries(self::WEB_SERVERS),
            phpVersion: $this->probe->phpVersion(),
            phpBinary: $this->probe->phpBinary(),
            composer: $this->probe->binary('composer'),
            node: $this->probe->binary('node'),
            git: $this->probe->binary('git'),
            databaseClients: $this->foundBinaries(self::DATABASE_CLIENTS),
            canRunProcesses: $this->probe->functionUsable('proc_open'),
            openBasedir: $this->probe->iniValue('open_basedir'),
        );
    }

    /**
     * ID, VERSION_ID and PRETTY_NAME from os-release, unquoted.
     *
     * The format is `KEY=value` lines where the value may be double- or
     * single-quoted. Only the three keys anything here consumes are kept —
     * parsing the rest would be an invitation to depend on it.
     *
     * @return array<string, string>
     */
    private function osRelease(): array
    {
        $text = null;

        foreach (self::OS_RELEASE_PATHS as $path) {
            $text = $this->probe->read($path);

            if ($text !== null) {
                break;
            }
        }

        if ($text === null) {
            return [];
        }

        $parsed = [];

        foreach (explode("\n", $text) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            if (! in_array($key, ['ID', 'VERSION_ID', 'PRETTY_NAME'], true)) {
                continue;
            }

            $value = trim($value);

            if (strlen($value) >= 2
                && ($value[0] === '"' || $value[0] === "'")
                && str_ends_with($value, $value[0])) {
                $value = substr($value, 1, -1);
            }

            if ($value !== '') {
                $parsed[$key] = $value;
            }
        }

        return $parsed;
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function existing(array $paths): array
    {
        return array_values(array_filter(
            $paths,
            fn (string $path): bool => $this->probe->isDirectory($path),
        ));
    }

    /**
     * @param  list<string>  $names
     */
    private function firstBinary(array $names): ?string
    {
        foreach ($names as $name) {
            if ($this->probe->binary($name) !== null) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function foundBinaries(array $names): array
    {
        return array_values(array_filter(
            $names,
            fn (string $name): bool => $this->probe->binary($name) !== null,
        ));
    }
}
