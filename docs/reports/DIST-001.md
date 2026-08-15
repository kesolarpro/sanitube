# DIST-001 — Distribution Core

**Status:** delivered
**Base:** `06a91c6` (main, after REL-001)
**Branch:** `claude/dist-001-distribution-core`

---

## What this ticket is actually about

Submission is **the one irreversible act in SaniTube.** Everything else —
importing, analysing, promoting, generating, assembling — happens inside the
platform and can be undone. A submitted release is in somebody else's system,
on its way to stores, and a duplicate submission is what gets a label's *whole
catalogue* flagged rather than one release.

Almost the entire ticket is about not doing it twice, and about not doing it at
all when something is missing.

**No Too Lost adapter.** DIST-001 builds the neutral engine; the first real
adapter is DIST-002.

## Three independent guards against a duplicate delivery

Each covers a case the others do not:

1. **`UNIQUE (release_id, provider)`** — the database refuses a second delivery
   row for the pair, whatever the code does.
2. **A guarded status transition** — the delivery is claimed with a conditional
   `UPDATE`, so two concurrent submitters cannot both proceed. Reading the
   status and then writing it would let them.
3. **A stable idempotency key** — `sha256(release uuid | distributor name)`,
   derived once and never regenerated.

The key is **derived, not random**, and that is the point. A random key stored
on the row is lost if the row was never written — which is precisely the crash
it exists to survive. Deriving it means the same key can be recomputed from
nothing. It embeds the *uuid*, not the primary key, because a key carrying an
internal id leaks row counts to whichever distributor is on the other end.

## Outage is not rejection

The distinction runs through the whole engine, and it took two test failures to
get right:

- A distributor that **refuses** the package → `REJECTED`, a verdict.
- A distributor that **cannot be reached** → `FAILED`, which is retryable and
  keeps the same key.
- A distributor that is **not configured** → an *error*, never a pass.

That last one was a real gap the tests caught: `ValidateDelivery` originally
skipped an unavailable distributor's verdict and returned "valid", which would
have told a label its release was ready to deliver on an installation that
cannot deliver anything. Unreachable and unconfigured now both produce errors,
and neither ever escapes as an exception — a distributor being down must not
break the screen a label is looking at.

An outage during submission leaves the delivery `FAILED` rather than stuck in
`SUBMITTING`. A row permanently mid-flight is a row nobody retries and nobody
cleans up.

## No ISRC is ever minted

A track without an active ISRC is a **validation error**, full stop. Assigning
one automatically to a recording that may already have been distributed under a
different ISRC is the mistake that cannot be taken back once a store has seen
it — and the platform has no way to know whether a legacy import already
carries one. The same applies to the release's UPC.

Assignment is a separate, deliberate service in a later ticket. A test asserts
that a failed validation created no identifier.

## The contract, finalised at five methods

ARCH-001 fixed the read-side and deferred the write-side to DIST-001 "when the
Release aggregate exists". It does now.

```php
validateRelease()  prepareRelease()  submitRelease()  deliveryStatus()  requestTakedown()
```

**Royalties and analytics are not here.** A distributor that reports earnings
and one that only delivers are both distributors, and a contract demanding both
would force every adapter to stub the half it does not do. They arrive as
capability interfaces when a real adapter needs them.

`prepareRelease` sits between validate and submit because uploading masters and
artwork is the slow, resumable part — folding it into `submitRelease` would
make every retry re-upload a set of masters.

Every write method takes the idempotency key.

## Two vocabularies

`DeliveryStatus` is what a *distributor* last reported.
`DistributionDeliveryStatus` is what SaniTube believes about the row — the same
split GEN-001 makes. Three of the local states have no provider counterpart at
all: `VALIDATING`, `READY` and `SUBMITTING` describe work SaniTube is doing.

`ACCEPTED` and `DELIVERED` are separate deliberately: a distributor accepting a
package means the metadata passed its checks, delivering it means the stores
have it, and weeks can pass between the two. A label chasing a release needs to
know which side of that line it is on.

## Attempts are recorded separately

"This release was rejected" and "we tried four times and the fourth failed for a
different reason than the first" are different facts, and only the second tells
a label whether the problem is their metadata or the distributor's service.
`distribution_attempts` is append-only, and every row carries the delivery's
idempotency key.

`response_summary` is a *summary*, never the raw payload: distributor responses
quote the request, and the request is signed.

## There is no `distributors` table

A distributor is an adapter plus a set of credentials, both of which live in
configuration. Making it a row would invite someone to create one the platform
has no code for.

## API

```
POST /api/v1/releases/{uuid}/distribution/{provider}/validate   read-only
POST /api/v1/releases/{uuid}/distribution/{provider}/submit
GET  /api/v1/distributions
GET  /api/v1/distributions/{uuid}
```

The provider is named in the **path**, not the body: which distributor receives
a release is part of what the request *is*, and a body field makes it look like
an option.

`external_release_id` and `idempotency_key` never cross the boundary — the
first identifies a record in someone else's account, the second is the token
that makes a repeat submission recognisable.

## Architecture amendment

**None to a validated schema.** Two new tables. `Distributor` gains its
write-side, which ARCH-001's own docblock scheduled for this ticket; the only
implementations are `NullDistributor` and the new `FakeDistributor`, both
written against it.

## Tests

**19 new tests. 570 tests / 1856 assertions** in the suite, 1 skipped (the real
ffprobe run). PHPStan level 6 clean — no ignores, no baseline. Pint clean.
Frontend build clean. `migrate → rollback → migrate` verified on SQLite locally
and by all four engines in CI.

| Requirement | Test |
| --- | --- |
| no distributor configured | `an_installation_with_no_distributor_refuses_clearly` |
| unconfigured ≠ approval | `the_null_distributor_never_reports_a_release_as_acceptable` |
| disabled name is `none` | `the_disabled_distributor_is_called_none` |
| draft not deliverable | `a_draft_release_cannot_be_delivered` |
| **no ISRC minted** | `a_track_with_no_isrc_blocks_delivery_and_no_isrc_is_minted` |
| UPC required | `a_release_with_no_upc_blocks_delivery` |
| sandbox warns, not blocks | `a_sandbox_distributor_is_warned_about_rather_than_blocked` |
| distributor refusal | `a_distributor_that_refuses_the_package_stops_the_submission` |
| **submitted once** | `a_release_is_delivered_once_and_the_second_attempt_is_refused` |
| DB refuses a duplicate | `the_database_refuses_two_deliveries_of_one_release_to_one_distributor` |
| key is stable + scoped | `the_idempotency_key_is_stable_across_attempts` |
| retry reuses the key | `a_retry_after_an_outage_reuses_the_same_key_and_creates_one_delivery` |
| outage → FAILED not stuck | `an_outage_leaves_the_delivery_failed_rather_than_stuck_submitting` |
| accepted → live | `a_delivery_follows_the_distributor_from_submitted_to_live` |
| outage during sync | `a_transient_outage_during_sync_does_not_move_the_delivery` |
| nothing to poll | `syncing_a_delivery_that_was_never_handed_over_asks_nothing` |
| takedown | `a_live_release_can_be_taken_down` |
| takedown refused | `nothing_that_was_never_delivered_can_be_taken_down` |
| attempts recorded | `every_conversation_with_the_distributor_is_recorded` |

## External blockers

**Too Lost credentials unavailable.** No distributor account is wired to this
platform, so no real adapter exists and no delivery has ever been made. The
fake distributor exercises validate, prepare, submit, poll and takedown without
a network. Recorded as **DIST-002**: needed before anything is actually
delivered, blocking nothing in development.

STO-002, AI-002 and GEN-002 remain outstanding and do not block this.
