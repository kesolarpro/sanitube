# AUDIT-001 — Who did what, to which object, when, and from where

## The gap

SaniTube recorded provenance in six places and nowhere.

`distribution_attempts.decided_by` knows who accepted a manual reference.
`track_candidates.reviewed_by` knows who reviewed a proposal. `ingestion_batches`
and `generation_projects` know who started them. Two more columns — 
`distribution_deliveries.created_by` and `music_generations.created_by` — were
declared and never written at all.

Every one of those answers a question about its own aggregate. None of them
answers what an operator actually asks:

- Who signed in from that address, and how many times did they fail first?
- Was anything **refused**, and what refused it?
- Who was made an OWNER?
- Did anybody mint a preview link for the unreleased master?

Two of those are about things that never touch a domain table, and one is about
attempts that were stopped — which by definition wrote nothing.

## What was built

A single append-only `audit_events` table, one writer, 24 named actions, and a
read-only screen behind `can.role:administer`.

### The recording is explicit, and that is the decision

The tempting design is a trait or a global model listener. It was rejected, and
ADR-0014 records why in full. The short version:

- `ResolveFailedJob` writes through a `ConnectionInterface` and shells out to
  `queue:retry`. A model hook never sees it — and the failed-job actions are
  among the few writes that most need auditing.
- **Refusals fire no model event.** Nothing is written when a submission is
  refused, and those are the most valuable lines in the log.
- A model event knows the row, not the act: `Release::update` fires for a title
  correction and for `markReady()` alike.
- There is no base model. All 21 domain models are `final` and extend
  Illuminate's directly, so "one place" would have been a trait on 22 files.

The cost is honest: an operation nobody remembers to audit is not audited. That
is the same trade `SafelyRetryable` makes, and for the same reason — a visible
omission beats a mechanism that is silently incomplete.

### The actor is resolved, never passed

`RecordAuditEvent` reads the guard. There is no parameter for the actor. A call
site that could name the actor could name somebody else.

Where nobody is authenticated, the actor is a **guest** (the action came over a
matched route) or the **system** (a command, a worker, the scheduler), and the
affected person is the *subject*. A completed password reset is a guest acting on
a user — which is the truth: at that instant nobody had authenticated.

**A finding, from a failing test.** The first version discriminated on
`runningInConsole()`. That asks about the *process*, not the action: it is true
for the entire PHPUnit run, so every unauthenticated request in CI was recorded
as the platform acting on itself. The discriminator is now whether the request
has a matched route, which is the question both the actor and the origin actually
need answered.

### The refusals are the point

Every wired call site records both outcomes. A log containing only what succeeded
describes a well-behaved installation and says nothing about the afternoon
somebody tried eleven times to get a submission to run again.

This is also where the log earns something the response cannot give.
`AuthenticateUser` has always said that its three refusals are indistinguishable
from outside — a login form that says "no such account" is an enumeration oracle
— and that *the logs distinguish them*. They now do:
`THROTTLED`, `INVALID_CREDENTIALS` and `ACCOUNT_INACTIVE` are three different
lines against the same account uuid. Safe, because the log is behind the
administer role; useful, because "somebody deactivated last week is still trying"
looks nothing like "a stranger is guessing".

The account is identified by **uuid, never by the submitted address**. A run of
failures against one person is what an administrator needs to see; a table
accumulating arbitrary strings a stranger typed is not. The lookup runs on every
failure, known address or not, so it adds no timing difference between the two.

### Redaction is structural, not a convention

`Redaction` filters every context array, bluntly on purpose — it is easier to
defend a rule that occasionally discards something useful than one that
occasionally keeps something dangerous.

| Rule | What it stops |
|---|---|
| Denylisted key fragments | `db_password`, `reset_token`, `x_signature`, `api_key_name` |
| **No URL at all** | signed URLs, private object URLs — without chasing per-provider signature parameters |
| No `Bearer …`, no PEM | credentials under an innocuous key name |
| Nothing over 200 chars, **discarded not truncated** | a truncated secret is a secret with its first 200 characters intact |
| 20 keys, one level of nesting | an audited operation filling the log with whatever it was given |

Deliberately *not* denied: `idempotency_key` and `object_key`. They are derived
from public data and are exactly what an operator needs — so the list names the
dangerous words rather than the word "key".

**What it cannot do**, stated rather than glossed: a short secret under an
innocuous key passes every rule. The filter removes the shapes that leak in
practice; it does not remove the need to think at the call site.

### Append-only, enforced rather than intended

- No `updated_at`. The model throws `AuditEventIsImmutable` on `updating` and on
  `deleting`.
- The retention prune removes whole periods through the query builder — a
  deliberately different act from holding a record and deleting it. There is no
  way to say "remove the events about this person".
- The prune records itself **before** it runs, so the gap in the history explains
  itself even if the delete then fails.
- `sanitube:audit:prune` is not scheduled by SaniTube. How long an installation
  must keep its history is a decision about that installation, and shipping a
  default that deletes a year on first cron run would be making it for them. The
  `--days` floor of 30 is clamped upward rather than validated: refusing a
  three-day retention outright only invites a scheduled `|| true`.
- `actor_id` is RESTRICT on delete and update — the same argument DIST-001-H1
  settled for `decided_by`.

### Writing is best-effort, and why that is allowed here

A failed write does not fail the operation; it is re-emitted through the
application log with the same fields. An attacker who breaks the table does not
thereby erase their trail.

That trade is defensible **only because of what this table is not**. It is
operational history. Nothing is derived from it, and no figure anywhere is
computed by reading it. If anything ever totals something from `audit_events`,
`RecordAuditEvent` has to become transactional first. Per the 2026-08-16 scope
correction, SaniTube performs no financial calculation, and this table is not
where that changes.

## Where it is wired

| Module | Recorded |
|---|---|
| Identity | sign-in, sign-in refused (three distinct reasons), sign-out, account created, reset requested, reset completed |
| Catalogue | candidate promoted (with `override`), candidate rejected, track credits updated |
| Releases | created, marked ready, reopened |
| Distribution | delivery submitted, takedown requested, reference decided by hand |
| Assets | preview link minted |
| Ingestion | import batch started |
| Generation | generation started, result selected |
| System | failed job run again, failure record removed, backup created, backup restored, audit log pruned |

Not recorded, deliberately:

- **Reading anything**, including the audit screen itself. A log that records its
  own readers grows by being looked at.
- **`sync` and `reconcile`** on a delivery. They ask a distributor a question and
  already write their own attempt rows; auditing them would bury the three lines
  that matter under polling.
- **Candidate revision.** It edits a draft nobody has acted on; every edit of
  every field is a changelog, not an audit log.
- **Deactivation, reactivation and role change.** SaniTube has no path that
  performs them — accounts are created by `sanitube:user:create` and altered by
  hand — so declaring the actions would put three entries in the filter list that
  no history will ever contain.

## What is not covered

- **The API surface is not audited.** `src/Api` authenticates with a shared token
  that names no person, so recording those calls as `guest` would be misleading
  and recording them as a user would be false. Giving them an honest actor
  identity is a ticket of its own (**AUDIT-002**), not a line to slip in here.
- **An audit write inside a rolled-back transaction rolls back with it.** Usually
  correct — the operation did not happen — but a refusal raised *inside* a
  transaction that then rolls back leaves no line. None of the wired call sites
  is in that position today; a future one could be.
- **A short secret under an innocuous key** survives redaction, as above.

## Mutation testing

Six injected, six killed, each by the assertion that names the property.

| # | Mutation | Killed by |
|---|---|---|
| M1 | Removed the "no URL" rule from `Redaction` | `no_url_is_ever_stored_signed_or_otherwise` |
| M2 | Snapshotted `$user->email` instead of `$user->name` | `the_actor_snapshot_never_holds_an_email_address` |
| M3 | Removed the `updating` guard on the model | `an_event_cannot_be_changed` |
| M4 | Removed the retention floor | `a_retention_below_the_floor_is_raised_rather_than_honoured` |
| M5 | Gave the deactivated-account refusal the same reason as a wrong password | `the_log_tells_the_three_refusals_apart_when_the_response_will_not` |
| M6 | Recorded the sign-out *after* `Auth::logout()` | `signing_out_names_the_person_rather_than_a_guest` |

M6 is the one worth noting: it does not change what is recorded, only *when* —
and the line silently becomes a guest acting on a user, which reads as a
completely different event.

## Gate

- 1195 tests, 5640 assertions, 1 skipped — green.
- PHPStan level 6, no baseline, no ignored errors — clean.
- Pint — clean. `vue-tsc` — clean. Vitest 16/16. Production build — clean.
- Six locales at parity, verified by the existing localisation suite.
- `PortabilityTest` extended: `audit_events` joins the tables whose enumerated
  columns must be `VARCHAR`, so adding an action stays a code change.
