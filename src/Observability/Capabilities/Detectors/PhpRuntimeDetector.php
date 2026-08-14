<?php

declare(strict_types=1);

namespace SaniTube\Observability\Capabilities\Detectors;

use SaniTube\Observability\Capabilities\Capability;
use SaniTube\Observability\Capabilities\CapabilityDetector;

final readonly class PhpRuntimeDetector implements CapabilityDetector
{
    public const MINIMUM_VERSION = '8.2.0';

    public const RECOMMENDED_VERSION = '8.3.0';

    public function detect(): Capability
    {
        $version = PHP_VERSION;

        if (version_compare($version, self::MINIMUM_VERSION, '<')) {
            return Capability::unavailable(
                key: 'php',
                label: 'PHP',
                detail: sprintf('PHP %s is below the required %s.', $version, self::MINIMUM_VERSION),
                remediation: sprintf(
                    'Switch the account or server to PHP %s or newer (PHP %s recommended).',
                    self::MINIMUM_VERSION,
                    self::RECOMMENDED_VERSION,
                ),
            );
        }

        if (version_compare($version, self::RECOMMENDED_VERSION, '<')) {
            return Capability::degraded(
                key: 'php',
                label: 'PHP',
                detail: sprintf('PHP %s is supported but not recommended.', $version),
                remediation: sprintf('Upgrade to PHP %s or newer.', self::RECOMMENDED_VERSION),
            );
        }

        return Capability::available('php', 'PHP', sprintf('PHP %s', $version));
    }
}
