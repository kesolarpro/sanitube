<?php

declare(strict_types=1);

namespace SaniTube\Audit\Enums;

/**
 * Whether the operation happened.
 *
 * **The refusals are the point.** A log containing only what succeeded
 * describes a well-behaved installation and says nothing about the afternoon
 * somebody tried eleven times to submit a release they had no role for. Every
 * refusal SaniTube already expresses as a machine-readable code is worth a
 * line here, and the code travels with it.
 */
enum AuditOutcome: string
{
    case Succeeded = 'SUCCEEDED';
    case Refused = 'REFUSED';
}
