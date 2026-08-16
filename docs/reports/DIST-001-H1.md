# DIST-001-H1 — When SaniTube cannot say whether the package arrived

**REVIEW_REQUIRED.** This changes the semantics of external submission, adds a
method to the `Distributor` contract, and introduces the one place in the
platform where a value that was never received from a distributor is written
as though it had been. It is not merged autonomously.

## The gap

DIST-001 treats every failure during submission the same way:

```php
try {
    $distributor->prepareRelease(...);
    $submission = $distributor->submitRelease(...);
} catch (Throwable $exception) {
    return $this->fail($delivery, $exception->getMessage(), $startedAt);
}
```

`FAILED` is submittable, so the interface offers a retry. That is right for a
connection that was refused and **wrong for a read that timed out**: the
request may have reached the distributor, the package may be in their system,
and a retry against a provider that does not honour idempotency keys is a
*second delivery* — the exact outcome the whole module exists to prevent.

The stable idempotency key is a mitigation, not a guarantee. No contract can
make a provider honour it, and DIST-001 does not claim otherwise.

There was no way for the platform to say **"I do not know"**.

## The design

### 1. A local status that is neither success nor failure

`DistributionDeliveryStatus::SubmittedUnconfirmed` (`SUBMITTED_UNCONFIRMED`).

- `isSubmittable()` → **false**. Saying "I do not know" costs a retry rather
  than granting one.
- `isPending()` → false. Nobody owes an answer, because nobody knows there is a
  question.
- `isTakedownable()` → false. There is no reference to take down.
- New `isUnknown()` → true. The one honest use of "unknown" in the enum: every
  other case is a claim, this one is the absence of one.

No migration — `status` is already `string(32)`.

### 2. Classification by *where* the failure happened

`prepareRelease()` and `submitRelease()` now sit in separate `try` blocks, and
that separation is the ticket's central claim:

| Failure | Outcome | Why |
|---|---|---|
| while **preparing** | `FAILED`, retryable | Preparation is upload. Nothing was submitted. |
| `SubmissionNotSent` from **submitting** | `FAILED`, retryable | The adapter *knows* the request never left: DNS, refused connection, rejected handshake. |
| anything else from **submitting** | `SUBMITTED_UNCONFIRMED` | A read timeout, a reset connection, a 502 from a gateway that had already forwarded the request. All compatible with the package having arrived. |

`SubmissionNotSent` is new, and adapters are never obliged to throw it. **Not
throwing it means "I do not know", which is the safe answer.** The conservative
case is the default; precision is opt-in.

### 3. A fourth attempt outcome

`DistributionAttemptOutcome::Unknown`. A separate case rather than a flavour of
`FAILED`, because `FAILED` is what retry logic reads: "it did not work" and "we
do not know whether it worked" call for opposite next moves, and a log that
records them identically cannot tell anybody which happened.

### 4. `findSubmission()` on the contract

```php
public function findSubmission(string $idempotencyKey): ?DistributorSubmission;
```

Three answers, and the difference between the second and third is the whole
reason the method exists:

- **a `DistributorSubmission`** — it arrived. Adopt its reference; the delivery
  becomes `SUBMITTED`, which is what it would have been had the response come
  back.
- **`null`** — the distributor looked and holds nothing under this key. Back to
  `FAILED`, retryable, same key.
- **`SubmissionLookupUnsupported`** — it *cannot* look. Not the same as holding
  nothing, and a retry is not safe.

Returning `null` because a method had to return something would turn every
provider without a lookup endpoint into one that confidently reports an empty
account. That is how a release gets delivered twice.

A transport failure while *asking* is the third case, not the second: "the
lookup timed out" is not evidence of an empty account.

### 5. A person, when the distributor cannot be asked

`resolveManually($delivery, $arrived, $externalReleaseId, $decidedBy, $note)`.

Without it, a delivery whose distributor has no lookup endpoint is stuck
forever. Being stuck is an honest description of the world; it is not a
workflow. So somebody looks at the distributor's own dashboard and says what
they found.

Three guards, each with a test:

- **A reference is required when the answer is "it arrived".** "It arrived but I
  cannot say under what reference" leaves a `SUBMITTED` row nobody can ever poll
  or take down again.
- **A note is required either way.** It is the only record of where the person
  looked.
- **`decided_by` is recorded**, from the session and never from the request — a
  decision that says who made it is only worth something if the caller could not
  choose. New nullable `unsignedBigInteger` on `distribution_attempts`, null on
  every attempt that was a conversation with a distributor. No foreign key:
  SEC-001 deactivates accounts rather than deleting them, and a constraint here
  would make an append-only log deletable by way of the users table.

`arrived: false` returns the delivery to `FAILED` rather than deleting
anything. The attempt history is what makes the decision reviewable.

## The interface

The delivery screen now draws **four** states apart, not three. The unconfirmed
one is offered *answers* rather than a retry: "Ask the distributor what they
hold", or "Record what you found". When the distributor is not configured here
and cannot be asked, the screen says so rather than leaving a button missing.

Both actions are behind `can.role:distribute`, and the resolution dialog uses a
select rather than a toggle — "arrived / never arrived" is a statement about the
world, and a switch labelled with one of the answers reads as a setting somebody
left on.

Six locales complete.

## Tests

16 domain tests in `tests/Feature/Distribution/UnknownSubmissionOutcomeTest.php`,
8 more in `DistributionScreensTest`. Every pre-existing distribution test passes
unmodified: the change is additive for behaviour they exercise.

### Mutation pass — 17 injected, 17 killed

| # | Regression injected | Killed by |
|---|---|---|
| M1 | a swallowed submission reported as an ordinary failure | unknown rather than failed |
| M2 | an unconfirmed delivery becomes retryable | cannot be submitted again |
| M3 | a refused connection treated as unknown | refused connection stays retryable |
| M4 | a failure while preparing treated as unknown | preparing stays retryable |
| M5 | an unknown attempt logged as a failure | recorded as unknown, not a failure |
| M6 | an unsupported lookup read as an empty account | cannot be asked leaves it unknown |
| M7 | a failed lookup read as an empty account | a failed lookup is not evidence |
| M8 | reconciling adopts no reference | reconciling adopts what they hold |
| M9 | anything can be reconciled | nothing to reconcile when known |
| M10 | a manual resolution needs no reference | claiming arrival without a reference |
| M11 | a manual resolution needs no reason | overruling without a reason |
| M12 | a manual resolution names nobody | a person is named for it |
| M13 | anyone may resolve a known delivery | cannot resolve a known delivery |
| M14 | the screen offers a retry on an unconfirmed delivery | offered answers rather than a retry |
| M15 | the reconcile action leaves the write role | a member can neither reconcile nor resolve |
| M16 | the decider taken from the request | a person is named for it |
| M17 | the resolution form accepts an empty reason | refused by the form |

**M4 survived the first run, and exposed a defective test.**
`a_failure_while_preparing_stays_retryable` used the fake's `down()`, which
breaks `validateRelease()` too — so the submission was refused during validation
and **never reached the prepare block at all**. The test had been passing while
covering a completely different path. The fake gained `failingPreparation()`,
which fails during upload and nowhere else, and the mutation now dies.

## What this does not do

- **No real distributor.** `DIST-002` stays `BLOCKED_EXTERNAL`. Which providers
  offer a lookup by idempotency key is an open question that only a real adapter
  answers, and the contract is written so that "cannot answer" is a first-class
  reply rather than a gap.
- **No automatic reconciliation.** There is no scheduled job that sweeps
  unconfirmed deliveries. Asking is a button, because a background process
  quietly turning "unknown" into "submitted" is the same class of mistake this
  ticket exists to prevent — just slower.

## For review

Four things a reviewer should weigh, because they are decisions rather than
consequences:

1. **The default is "unknown".** Any unclassified throwable from
   `submitRelease()` blocks the retry. That is deliberately conservative and it
   *will* strand deliveries that in fact never arrived, until somebody
   reconciles or looks. The alternative strands nothing and risks duplicates.
2. **`findSubmission()` widens the contract to six methods.** DIST-001 closed it
   at five and said so. This is a genuine amendment.
3. **A hand-typed external reference is written as though received.** Guarded by
   a required note and a recorded actor, and there is no other way out for a
   provider with no lookup endpoint — but it is a real hole in the "SaniTube
   never invents a provider's data" rule and should be signed off explicitly.
4. **`decided_by` has no foreign key.** Argued above; a reviewer may prefer one.

## Gate

| Check | Result |
|---|---|
| PHPUnit | 974 passed, 1 skipped, 4225 assertions (was 950 / 4130) |
| PHPStan level 6+ | no errors, no baseline, no ignores |
| Pint | passed |
| vue-tsc | passed |
| Vitest | 16 passed |
| Production build | built |
| Localisation gate | 33 passed — six locales complete |
| Mutation pass | 17 injected, 17 killed |

---

# Audit — a later session, before review

This branch was re-read from scratch against the four points above, and the
rest of the module with it. The four decisions stand as written. Two defects
turned up that were not among them, both fixed here.

## 1. A reconciliation that failed was logged as a submission that failed

`fail()` hardcoded `DistributionAction::Submit`. `reconcile()` ends there on
the "the distributor holds nothing" path, so the attempt history recorded a
**submission failure** for an event that was a *reconciliation concluding the
package never arrived*.

Those are different accounts of the world, and the difference is the whole
ticket. "The submission failed" invites a retry. "We asked, and they never got
it" is the *evidence that makes a retry safe*. A log that says the first when
the second happened misdescribes the only irreversible act in the platform, in
the one record a person reads before handing a release over again.

`fail()` now takes the action, defaulting to `Submit`.

The same path also wrote **two** attempt rows — one `Reconcile/Succeeded`
saying the lookup worked, one `Submit/Failed` saying the delivery failed. One
event, one row: `Reconcile/Failed`, with the summary that says what was found.

## 2. Neither way out of SUBMITTED_UNCONFIRMED was guarded against a second answer

`handle()` claims a delivery with a conditional `UPDATE`, and the reason is
written into the file: reading the status and then writing it lets two
concurrent submitters both get through.

`reconcile()` and `resolveManually()` did exactly that. Both read
`$delivery->status->isUnknown()` from an in-memory instance and then wrote.

The failure is not hypothetical, and it is worse than a lost update. Two
operators can look at the same stuck delivery and reach **opposite**
conclusions — one records "it arrived, reference TL-99321", the other "nothing
in the dashboard". Both pass the unguarded check, both write a decision, and
the delivery keeps whichever landed last while the append-only log — the thing
that exists to make the decision reviewable — holds two contradicting rows.

The reverse ordering is the dangerous one: a reconcile request already in
flight when a person confirms the package *did* arrive would drag the delivery
back to `FAILED`, and `FAILED` is submittable. That is a second delivery, which
is the outcome this module exists to prevent.

`claimUnknown()` re-reads the row under `lockForUpdate()` inside a transaction
and re-checks that the question is still open. Whoever answers first is the
answer; the second is told `NOT_RECONCILABLE`.

A re-read rather than a conditional `UPDATE` because the value being written is
not one fixed status — it is whichever of three outcomes the caller arrived at,
and the row has to be held while that is worked out. Same shape as
`RevokeExternalIdentifier`, which had the same problem for the same reason.

### Mutation pass on the fixes

| # | Mutation | Result |
|---|---|---|
| M1 | The reconcile failure is logged as a submission again | killed |
| M2 | The claim stops checking that the question is still open | killed |
| M3 | The claim re-reads nothing and trusts the caller's instance | killed |
| M4 | Reconcile logs the outcome twice again | killed |

## What the audit did not find

- **Authorization.** Both new routes sit inside `can.role:distribute`, with
  the rest of the distribution actions. `decided_by` comes from
  `$request->user()`, never from the request body — a `decided_by` field posted
  by a client is ignored, and `ResolveDeliveryRequest` does not accept one.
- **Storage leakage.** Nothing in the delivery payloads carries a disk, a
  bucket, an object key or a provider URL.
- **Identifier invention.** No ISRC, UPC or EAN is minted anywhere on this
  branch. The hand-typed value is a *distributor's own submission reference*,
  which is a different kind of thing and is flagged as review point 3 either
  way.
- **Portability.** The one migration adds a nullable `decided_by` integer. No
  `json()`, no enum column, no engine-specific SQL. `lockForUpdate()` compiles
  to `FOR UPDATE` on MySQL and MariaDB and is a no-op on SQLite, where the
  write transaction already serialises — the four-engine matrix covers it.

## Merged with main

`OPS-001` and `REL-002` landed underneath this branch. REL-002 is the relevant
one: it removed `SubmitDelivery::wasUnreachable()`, the substring match on an
English message, and this branch's version of it went with it. The unreachable
check is now `$verdict->has('DISTRIBUTOR_UNREACHABLE')`.

## Gate after the audit

1070 passed, 1 skipped, 4705 assertions · PHPStan clean · Pint clean ·
4 further mutations injected, 4 killed.

**Still REVIEW_REQUIRED. Still not merged.** The four decisions at the top of
this document are what needs a signature; nothing in this audit changes them.
