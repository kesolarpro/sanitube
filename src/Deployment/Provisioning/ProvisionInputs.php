<?php

declare(strict_types=1);

namespace SaniTube\Deployment\Provisioning;

use SaniTube\Deployment\Exceptions\ProvisionException;

/**
 * Everything a generated service file may contain, validated at the door.
 *
 * Generated units and vhosts are *privileged text*: systemd will run what a
 * unit says as the user it names, nginx will serve what a server block
 * points at. A value that smuggles a newline into a unit file is a second
 * directive; a domain with a brace in it rewrites a server block. So nothing
 * here escapes — it **refuses**. Escaping would mean believing an operator
 * meant to name their instance `a\nExecStart=...`, and nobody means that.
 *
 * Every value is an input. The defaults live in the command that collects
 * them — from the running PHP, from the current user, from configuration —
 * never in here and never as literals in a template: two installations on
 * one VPS differ in exactly these values, and §71 forbids baking any of
 * them in.
 */
final readonly class ProvisionInputs
{
    private const INSTANCE = '/^[a-z][a-z0-9-]{0,31}$/';

    private const DOMAIN = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i';

    private const ACCOUNT = '/^[a-z_][a-z0-9_-]{0,31}\$?$/';

    /**
     * Absolute, and free of everything that means something to a unit file,
     * a crontab or an nginx block: whitespace, quotes, semicolons, braces.
     */
    private const PATH = '/^\/[^\s\'";{}]+$/';

    private const SIZE = '/^[0-9]{1,5}[kmg]?$/i';

    public function __construct(
        public string $instance,
        public string $applicationPath,
        public string $phpBinary,
        public string $user,
        public string $group,
        public ?string $domain,
        public ?string $phpFpmSocket,
        public string $uploadMax,
    ) {
        self::match('instance', $instance, self::INSTANCE, 'lower-case letters, digits and dashes, starting with a letter');
        self::match('application path', $applicationPath, self::PATH, 'an absolute path without spaces or quoting characters');
        self::match('php binary', $phpBinary, self::PATH, 'an absolute path without spaces or quoting characters');
        self::match('user', $user, self::ACCOUNT, 'a system account name');
        self::match('group', $group, self::ACCOUNT, 'a system group name');
        self::match('upload size', $uploadMax, self::SIZE, 'a size like 512m');

        if ($domain !== null) {
            self::match('domain', $domain, self::DOMAIN, 'a DNS name like sanitube.example');
        }

        if ($phpFpmSocket !== null) {
            self::match('php-fpm socket', $phpFpmSocket, self::PATH, 'an absolute path without spaces or quoting characters');
        }
    }

    private static function match(string $label, string $value, string $pattern, string $expected): void
    {
        if (preg_match($pattern, $value) !== 1) {
            throw ProvisionException::invalid($label, $expected);
        }
    }
}
