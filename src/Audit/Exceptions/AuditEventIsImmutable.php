<?php

declare(strict_types=1);

namespace SaniTube\Audit\Exceptions;

use LogicException;

/**
 * Somebody tried to change history.
 *
 * A `LogicException` rather than a domain refusal, because this is never a
 * user's decision going wrong — there is no screen that offers it, no route
 * that reaches it, and no valid reason to want it. Reaching this is a
 * programming mistake, and it should stop the request that made it rather than
 * be caught and turned into a polite message.
 */
final class AuditEventIsImmutable extends LogicException
{
    public static function cannotBeChanged(): self
    {
        return new self(
            'An audit event cannot be changed. The log records what happened; correcting it would '
                .'make it a record of what somebody later preferred.',
        );
    }

    public static function cannotBeDeleted(): self
    {
        return new self(
            'An audit event cannot be deleted individually. Rows leave only through the retention '
                .'prune, which removes whole periods and records that it did so.',
        );
    }
}
