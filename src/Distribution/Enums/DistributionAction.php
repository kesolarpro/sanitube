<?php

declare(strict_types=1);

namespace SaniTube\Distribution\Enums;

/**
 * What an attempt was trying to do.
 */
enum DistributionAction: string
{
    case Validate = 'VALIDATE';
    case Prepare = 'PREPARE';
    case Submit = 'SUBMIT';
    case Poll = 'POLL';
    case Takedown = 'TAKEDOWN';
}
