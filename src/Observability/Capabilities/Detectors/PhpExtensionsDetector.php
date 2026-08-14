<?php

declare(strict_types=1);

namespace SaniTube\Observability\Capabilities\Detectors;

use SaniTube\Observability\Capabilities\Capability;
use SaniTube\Observability\Capabilities\CapabilityDetector;

final readonly class PhpExtensionsDetector implements CapabilityDetector
{
    /**
     * Extensions without which SaniTube cannot boot or cannot do its job.
     * Every one of these is available on a standard cPanel PHP build.
     */
    public const REQUIRED = [
        'ctype',
        'curl',
        'dom',
        'fileinfo',
        'filter',
        'hash',
        'json',
        'mbstring',
        'openssl',
        'pcre',
        'pdo',
        'session',
        'tokenizer',
        'xml',
        'zip',
    ];

    /**
     * @param  list<string>  $required
     */
    public function __construct(private array $required = self::REQUIRED) {}

    public function detect(): Capability
    {
        $missing = array_values(array_filter(
            $this->required,
            static fn (string $extension): bool => ! extension_loaded($extension),
        ));

        if ($missing !== []) {
            return Capability::unavailable(
                key: 'php_extensions',
                label: 'PHP extensions',
                detail: 'Missing: '.implode(', ', $missing).'.',
                remediation: 'Enable the missing extensions (cPanel: MultiPHP INI Editor / "Select PHP Version"; '
                    .'Debian/Ubuntu: apt install php-<ext>; AlmaLinux/Rocky: dnf install php-<ext>).',
            );
        }

        return Capability::available(
            key: 'php_extensions',
            label: 'PHP extensions',
            detail: sprintf('%d required extensions present.', count($this->required)),
        );
    }
}
