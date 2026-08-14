<?php

declare(strict_types=1);

namespace SaniTube\Observability\Capabilities\Detectors;

use SaniTube\Observability\Capabilities\Capability;
use SaniTube\Observability\Capabilities\CapabilityDetector;
use SaniTube\Storage\StorageManager;
use Throwable;

final readonly class ObjectStorageDetector implements CapabilityDetector
{
    public function __construct(private StorageManager $storage) {}

    public function detect(): Capability
    {
        $name = $this->storage->defaultName();

        if (! $this->storage->has($name)) {
            return Capability::unavailable(
                key: 'object_storage',
                label: 'Object storage',
                detail: sprintf('SANITUBE_STORAGE_PROVIDER is set to [%s], which is not configured.', $name),
                remediation: 'Set SANITUBE_STORAGE_PROVIDER to one of: '.implode(', ', $this->storage->names()).'.',
            );
        }

        try {
            $provider = $this->storage->provider($name);
        } catch (Throwable $exception) {
            return Capability::unavailable(
                key: 'object_storage',
                label: 'Object storage',
                detail: sprintf('Provider [%s] could not be resolved: %s', $name, $exception->getMessage()),
                remediation: 'S3, R2 and B2 require the league/flysystem-aws-s3-v3 package: '
                    .'composer require league/flysystem-aws-s3-v3',
            );
        }

        if (! $provider->supportsTemporaryUrls()) {
            return Capability::degraded(
                key: 'object_storage',
                label: 'Object storage',
                detail: sprintf('Using [%s], which cannot issue expiring URLs.', $name),
                remediation: 'Fine for a single server. Configure S3-compatible storage before enabling '
                    .'streaming or large distributor hand-offs.',
            );
        }

        return Capability::available(
            key: 'object_storage',
            label: 'Object storage',
            detail: sprintf('Using [%s] with expiring URLs.', $name),
        );
    }
}
