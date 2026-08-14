<?php

declare(strict_types=1);

namespace SaniTube\Observability\Capabilities;

enum CapabilityStatus: string
{
    /** Present and usable. */
    case Available = 'available';

    /** Missing, and something the platform genuinely needs. */
    case Unavailable = 'unavailable';

    /** Present but running in a reduced mode (database queue instead of Redis). */
    case Degraded = 'degraded';

    /** Absent by choice; nothing is broken. */
    case Optional = 'optional';

    /**
     * Whether this status should stop an installation or a deployment.
     */
    public function isBlocking(): bool
    {
        return $this === self::Unavailable;
    }

    public function label(): string
    {
        return __('sanitube.capabilities.status.'.$this->value);
    }
}
