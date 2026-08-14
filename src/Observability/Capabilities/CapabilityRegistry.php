<?php

declare(strict_types=1);

namespace SaniTube\Observability\Capabilities;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs the configured detectors and assembles a {@see CapabilityReport}.
 *
 * A detector that blows up is itself reported as an unavailable capability:
 * the health screen must survive a broken environment, since that is exactly
 * when an operator needs it.
 */
final class CapabilityRegistry
{
    /**
     * @param  list<class-string<CapabilityDetector>>  $detectors
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $detectors,
    ) {}

    public function report(): CapabilityReport
    {
        $capabilities = [];

        foreach ($this->detectors as $detector) {
            $capabilities[] = $this->run($detector);
        }

        return new CapabilityReport($capabilities);
    }

    /**
     * @param  class-string<CapabilityDetector>  $detector
     */
    private function run(string $detector): Capability
    {
        try {
            return $this->container->make($detector)->detect();
        } catch (Throwable $exception) {
            return Capability::unavailable(
                key: Str::snake(class_basename($detector)),
                label: Str::headline(class_basename($detector)),
                detail: 'Detection failed: '.$exception->getMessage(),
                remediation: 'This capability could not be inspected. Resolve the other failures reported here '
                    .'first — a missing database or cache backend makes several detectors unreadable — '
                    .'then check the application log.',
            );
        }
    }
}
