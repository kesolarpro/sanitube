<?php

declare(strict_types=1);

namespace SaniTube\Observability\Capabilities;

/**
 * Inspects one aspect of the runtime environment.
 *
 * Detectors must never throw: a detector that cannot determine the state of
 * its subject reports it as unavailable with an explanation.
 */
interface CapabilityDetector
{
    public function detect(): Capability;
}
