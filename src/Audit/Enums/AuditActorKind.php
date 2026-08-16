<?php

declare(strict_types=1);

namespace SaniTube\Audit\Enums;

/**
 * Who or what took the action.
 *
 * `Guest` exists because the most valuable line in an authentication log is
 * about somebody who is *not* signed in. A failed sign-in with a null actor
 * and a null everything-else is indistinguishable from a bug; a failed
 * sign-in by a guest, from an address, at a time, is a fact.
 *
 * `System` is the scheduler, a console command, a queued job — anything with
 * no person behind it. It is never a fallback for "could not work out who":
 * that case does not arise, because the actor is resolved from the
 * authenticated session at the moment of the write and there is no third
 * possibility.
 */
enum AuditActorKind: string
{
    case User = 'user';
    case System = 'system';
    case Guest = 'guest';
}
