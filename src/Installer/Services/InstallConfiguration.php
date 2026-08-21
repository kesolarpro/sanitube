<?php

declare(strict_types=1);

namespace SaniTube\Installer\Services;

use SaniTube\Installer\Exceptions\InstallConfigurationException;

/**
 * An unattended install, described as a file.
 *
 * `sanitube:install --config=/root/sanitube-install.conf` reads key=value
 * lines — the `.env` dialect, because the operator already knows it — and
 * applies the allowlisted keys to the environment before the stages run. This
 * is how a VPS bootstrap installs without a terminal conversation and without
 * putting a single secret on a command line, where argv and shell history
 * would keep it.
 *
 * **The allowlist is the same list the web installer may write**, plus the
 * owner's identity — deliberately. Both installers exist so an operator never
 * hand-edits `.env`; a config file that could write arbitrary keys would be a
 * third, undocumented installer with more power than the other two, and the
 * key nobody meant to set (`APP_KEY=`, say) would be the one that hurt.
 *
 * **A readable secret file is refused, not warned about.** The file carries a
 * database password and possibly the owner's; on a shared machine, mode 0644
 * means every other account has already had the chance to read it. Refusing
 * loudly before anything runs is the only answer that does not depend on
 * nobody having looked.
 */
final readonly class InstallConfiguration
{
    /**
     * Environment keys a config file may set. The web installer's list, kept
     * in step by a test, so the two unattended paths stay one capability.
     */
    private const ENVIRONMENT_KEYS = [
        'APP_URL',
        'DB_CONNECTION',
        'DB_HOST',
        'DB_PORT',
        'DB_DATABASE',
        'DB_USERNAME',
        'DB_PASSWORD',
    ];

    /**
     * Keys consumed by the installer itself, never written to `.env`.
     */
    private const OWNER_KEYS = ['OWNER_NAME', 'OWNER_EMAIL', 'OWNER_PASSWORD'];

    /**
     * @param  array<string, string>  $environment  allowlisted keys for .env
     * @param  array<string, string>  $owner  OWNER_* keys, held apart
     */
    private function __construct(
        public array $environment,
        public array $owner,
    ) {}

    public static function fromFile(string $path): self
    {
        if (! is_file($path)) {
            throw InstallConfigurationException::missing($path);
        }

        $permissions = fileperms($path);

        // 0077: any group or world bit — read, write or execute — disqualifies.
        // Checking only the read bits would bless a file another account could
        // rewrite, which is worse than one it could read.
        if ($permissions !== false && ($permissions & 0077) !== 0) {
            throw InstallConfigurationException::tooOpen($path, $permissions & 0777);
        }

        $raw = @file_get_contents($path);

        if ($raw === false) {
            throw InstallConfigurationException::unreadable($path);
        }

        return self::fromString($raw);
    }

    /**
     * Parsing split from the file so tests exercise every shape of content
     * without inventing permission fixtures for each.
     */
    public static function fromString(string $raw): self
    {
        $environment = [];
        $owner = [];

        foreach (explode("\n", $raw) as $number => $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (! str_contains($line, '=')) {
                throw InstallConfigurationException::unparseable($number + 1);
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = self::unquote(trim($value));

            if (in_array($key, self::ENVIRONMENT_KEYS, true)) {
                $environment[$key] = $value;

                continue;
            }

            if (in_array($key, self::OWNER_KEYS, true)) {
                $owner[$key] = $value;

                continue;
            }

            // Named, not ignored: a silently dropped key is a typo the
            // operator discovers three stages later as a database error.
            throw InstallConfigurationException::unknownKey($key, array_merge(self::ENVIRONMENT_KEYS, self::OWNER_KEYS));
        }

        return new self($environment, $owner);
    }

    public function ownerName(): ?string
    {
        return $this->value($this->owner, 'OWNER_NAME');
    }

    public function ownerEmail(): ?string
    {
        return $this->value($this->owner, 'OWNER_EMAIL');
    }

    public function ownerPassword(): ?string
    {
        return $this->value($this->owner, 'OWNER_PASSWORD');
    }

    /**
     * @param  array<string, string>  $values
     */
    private function value(array $values, string $key): ?string
    {
        $value = $values[$key] ?? '';

        return trim($value) === '' ? null : trim($value);
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2
            && ($value[0] === '"' || $value[0] === "'")
            && str_ends_with($value, $value[0])) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
