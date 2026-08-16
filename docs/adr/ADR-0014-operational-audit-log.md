# ADR-0014 — The operational audit log

**Status:** accepted
**Date:** 2026-08-16
**Ticket:** AUDIT-001

## Context

SaniTube records provenance in six places and nowhere. `distribution_attempts`
knows who decided a manual reference, `track_candidates` knows who reviewed a
proposal, `ingestion_batches` and `generation_projects` know who started them.
Every one of those answers a question about *its own aggregate*, and none of
them answers the questions somebody actually asks after an incident:

- Who signed in from that address last Tuesday, and how many times did they fail
  first?
- Was anything refused, and what refused it?
- Who was made an OWNER, and when?
- Did anybody mint a preview link for the unreleased master?

Those questions cross every module, and two of them are about things that never
touch a domain table at all.

## Decision

A single append-only `audit_events` table, written through one service, with the
recording done **explicitly at the call sites** rather than by a global hook.

### Explicit call sites, not model events

The tempting design is a trait or a global `Model::created` listener: one place,
every table, no call sites to remember. It was rejected, and the reasons are
specific to this codebase:

- **Bulk writes bypass model events entirely.** `ResolveFailedJob` operates on a
  `ConnectionInterface` and shells out to `queue:retry`; the identity migration
  updates through `DB::table()`. A model hook would silently miss the queue
  actions — which are among the few writes that *must* be audited.
- **Refusals have no model event.** Nothing is written when a submission is
  refused, and the refusals are the most valuable lines in the log.
- **A model event knows the row, not the act.** `Release::update` fires for
  a title correction and for `markReady()` alike, and the audit log would have to
  guess which happened from the changed attributes.
- **There is no base model to hook.** All 21 domain models extend Illuminate's
  directly and are `final`, so "one place" would have meant a trait on 22 files —
  which is not one place, and is one file away from being wrong.

The cost is real: an operation somebody forgets to audit is not audited. That is
the same trade `SafelyRetryable` makes and for the same reason — an omission that
is visible beats a mechanism that is silently incomplete.

### The actor is resolved, never passed

`RecordAuditEvent` reads the authenticated user from the guard. There is no
parameter for it. A call site that could name the actor could name somebody else,
and a log whose attribution is an argument can be made to lie by the code it is
watching.

Where nobody is authenticated, the actor is a **guest** (the action arrived over
a matched route) or the **system** (a command, a worker, the scheduler), and the
affected person is recorded as the *subject*. A completed password reset is
therefore a guest acting on a user, which is what actually happened.

The discriminator is whether the request has a matched route, not
`runningInConsole()`. The latter asks about the process rather than the action:
it is true for the whole test suite, and using it recorded every unauthenticated
request in CI as the platform acting on itself.

### Redaction is structural

Context passes through `Redaction`, which is blunt on purpose:

1. Denylisted key fragments (`password`, `token`, `secret`, `credential`,
   `signature`, …) are replaced whatever they hold. Note the absence of a bare
   `key`: `idempotency_key` and `object_key` are derived from public data.
2. **No URL is stored, signed or not.** Detecting "signed" specifically means
   tracking every provider's signature parameter, which is a race nobody wins.
3. No `Bearer …` value and no PEM block, under any key name.
4. Nothing over 200 characters, **discarded rather than truncated** — a
   truncated secret is a secret with its first 200 characters intact.

Plus two bounds that are about the log rather than about secrets: 20 keys, one
level of nesting.

What this cannot do: a short secret under an innocuous key passes every rule. The
filter removes the *shapes* that leak in practice; it does not remove the need to
think at the call site.

### Append-only, enforced

There is no `updated_at`. The model throws on `updating` and on `deleting`. The
retention prune removes whole periods through the query builder — a deliberately
different act from holding a record and deleting it — and records that it ran,
**before** it runs, so the gap explains itself even if the delete then fails.

`actor_id` is `RESTRICT` on delete and on update, the same argument DIST-001-H1
settled for `decided_by`: cascade would erase history by way of the users table,
and null-on-delete would leave a line that says a person acted and cannot say who.
SaniTube already deactivates rather than deletes; this makes the schema say so.

### Best-effort writing

A failed audit write does not fail the operation. It is re-emitted through the
application log with the same fields, so an attacker who breaks the table does
not thereby erase their trail.

That trade is defensible **only because of what this table is not**. It is
operational history; nothing is derived from it and no figure anywhere is
computed by reading it. If anything ever totals something from `audit_events`,
`RecordAuditEvent` has to become transactional first.

### Not a ledger

Per the 2026-08-16 scope correction, SaniTube performs no financial calculation.
This table is not an exception and must not become one.

## Consequences

- 24 named actions, each declaring its own subject kind, so two call sites
  recording the same action cannot disagree about what the subject uuid means.
- Adding an auditable operation is a code change in `AuditAction`, which is the
  direction the enum is meant to work in.
- Deactivation, reactivation and role change have **no** cases, because SaniTube
  has no path that performs them. Whoever builds user administration adds them.
- The API surface (`src/Api`) is **not** audited. It authenticates with a shared
  token that names no person, so recording those calls as `guest` would be
  misleading. Giving them an honest actor identity is AUDIT-002.
- The asset-preview log line moved from `Log::info` into the audit table. It was
  always the right fact; it now has a home that does not rotate.
