# PROD-005 — taking back occasions whose worker never came home

PROD-002 made claiming atomic so two workers cannot both take one occasion.
**Nothing made a claim expire.** A process killed between claiming and acting
left the occasion CLAIMED for ever, invisible to everything: the scheduler will
not re-open a date that already has a slot, and no worker will take one that is
not PENDING. PROD-004 named the gap in a docblock and left it —
*"PROD-005's reaper is what should decide when a claim is stale enough to take
back"* — and this is that.

---

## The question is not "is this claim old"

It is **"was anybody asked"**. An occasion may have reached a supplier before its
worker died, and asking a second supplier for an occasion already paid for is
precisely the failure the slot lifecycle exists to prevent. Three cases:

| State | What the reaper does | Why |
| --- | --- | --- |
| No generation, no attempted supplier | back to **PENDING** | nothing was spent |
| A generation exists | **nothing, at any age** | `SettleProductionSlot` owns it |
| A supplier was asked, no generation | **UNKNOWN_RESULT**, never retried | nobody knows what came back |

`ProduceForProductionSlot` writes the supplier's name *before* calling — that
ordering, added in PROD-004 for a different reason, is exactly what makes case 3
detectable.

## UNKNOWN_RESULT is not FAILED

A failure is something that did not happen. This is something that **may** have
happened. The two call for different actions — "look at this" versus "look at
this **and** check whether you were charged" — so they are different states, per
§13.

Retrying would risk paying twice for one occasion. Recording a failure would
tell an operator nothing happened, which may be false. So the platform stops and
asks for a person, and the audit line names the suppliers to go and ask.

Named `UNKNOWN_RESULT` rather than `UNKNOWN`: the platform is not unsure what
state the occasion is in — it knows exactly, which is that the *result* was
lost. The longer name also keeps it clear of the `UNKNOWN` commercial rights
use, where "not yet determined" is the ordinary starting state and wants none of
this one's alarm.

## WHO CALLS THIS IN PRODUCTION?

```
sanitube:production:run              (ProductionServiceProvider::boot)
  -> BackgroundWork::isPaused        (refuses first — a recovered occasion is one
                                      a resumed platform immediately acts on)
  -> ReclaimProductionSlots::handle  ← PROD-005, and it runs FIRST
  -> SettleProductionSlot::handle
  -> ProduceForProductionSlot::handle
```

Reclaiming runs **first**, so an occasion abandoned by a dead worker is taken
back and then done properly by the *same* run: a crash costs one cycle rather
than for ever. It still runs under `--settle-only`, because taking back an
occasion nobody is working on starts nothing.

`the_run_command_recovers_before_it_starts_anything` claims a slot, kills the
worker, travels past the lease and asserts one recovery **and** one generation
in a single artisan invocation.

## The lease

`config/production.php` — `claim_lease_seconds`, default **3600**. The number is
a trade in one direction only: too short and a slow worker has its occasion
taken from underneath it, which on a plan that may act alone means two suppliers
asked for one occasion. Too long only means a stuck occasion is noticed late.

A lease of zero or less is refused in code and falls back to the default: it
would reclaim a claim the instant it was taken, which is worse than having no
reaper at all.

`reclaim_batch` bounds one pass, so recovery after a long outage is a series of
short passes rather than one a shared host kills halfway through.

## Nothing double-executes

Every reclaim is a guarded `UPDATE` repeating **every** condition that made it
safe — status, absence of a generation, and the lease. Between reading a row and
writing it, a worker may have picked it up, recorded a generation, or been
reclaimed by another reaper, and each of those makes the update match zero rows
instead of undoing somebody's work. Running the reaper twice changes nothing the
first pass did not already do.

Cutoffs are computed in PHP, never in SQL — ADR-0017: subtracting from a
timestamp in raw SQL underflows on an unsigned column, and the engines disagree
about the arithmetic anyway.

## Verification

- **20 tests, 138 assertions** in `tests/Feature/Production/ProductionClaimRecoveryTest.php`
- Full suite: **1905 passed, 1 skipped, 24807 assertions**
- PHPStan level 6 clean, Pint clean, vue-tsc clean, Vitest 23 passed

### A defect the tests caught

The first version put `attempted_providers` into the audit context as a **list**.
`Redaction::scrub()` keeps only entries whose key is a *name* — every element of
a list has an integer key — so the field was stored as an empty array. The audit
line meant to tell an operator whose dashboard to open would have told them
nothing. Fixed by joining the names into a string, which reads better anyway.

### Mutation testing — 12 mutations, 10 killed, 2 equivalent

Killed: retrying an occasion that reached a supplier (M1), recording a lost
answer as a failure (M3), honouring a lease of zero (M6), dropping the reclaim
guards wholesale (M5) or individually (M5b generation, M5c lease), dropping the
unknown-path guards (M7), losing the audit line (M8), unbounding the pass (M9).

**Three survivors in the first round, and they had one cause.** M2 (the early
return for an in-flight occasion), M4 (the lease filter on the SELECT) and M5
(the guards on the `UPDATE`) all survived — because **nothing tested the guarded
updates themselves**. The race test I had written did not reach them: it called
`handle()` twice, and the second call re-read the row, so the stale-read path was
never exercised.

Fixed with three real-race tests using the house pattern PROD-002 established —
intruding from *inside* the window on the model's own `retrieved` event, with a
raw statement that does not go through Eloquent. A live worker re-claims
mid-pass; a generation is recorded mid-pass; and a worker that was merely slow
records its generation just as the reaper is about to declare the answer lost.

With the guards now independently killed, **M2 and M4 are equivalent mutants**,
and provably so rather than by assertion: both remove a check that the
authoritative `UPDATE` repeats. M4 additionally cannot starve a bounded batch,
because `orderBy('claimed_at')` ascending puts expired claims ahead of live ones
by construction.

This is the same shape as PROD-004's M12, and it is a deliberate property rather
than a recurring oversight: **the SELECT is a suggestion, the UPDATE is the
decision.** Recorded as such.

## State

`CODE READY`. The recovery path needs no supplier and no credential; it is
exercised end to end against the fake provider and the real artisan command.
