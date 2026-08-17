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

    /**
     * A server-to-server call that authenticated with a shared token.
     *
     * AUDIT-001 left the API out rather than lie about it, and this is why:
     * a shared token **names no person**. Recording those calls as a user
     * would invent one; recording them as a guest would say nobody was
     * authenticated when somebody was, and would put a release submission
     * beside a failed sign-in as though they were the same kind of event.
     *
     * So the fourth answer is the true one — *something* authenticated, and it
     * was not a human. What it was goes in `actor_label` as the client's
     * configured name. Never the token, never a prefix of it, never a hash
     * that could be checked against a guess.
     */
    case ApiClient = 'api_client';
}
