# PROD-004 — the occasion becomes a request, and comes back

**The production engine could decide and never act.** PROD-003 built
`WorkProductionSlot`, which claims a slot, counts what the plan already has, and
leaves the slot CLAIMED — its docblock says *"what happens next is generation,
and whatever does it settles this occasion"*. Nothing was that next thing.

`grep -rn "WorkProductionSlot" src/` returned only its own file. No route, no
command, no job, no listener. Under §2 the entire planner was incomplete: a
working engine and one that did nothing were indistinguishable from the outside.

---

## What was built

| Piece | What it is |
| --- | --- |
| `ProduceForProductionSlot` | decides, asks a supplier, records what it asked and whom |
| `SettleProductionSlot` | reads what the supplier eventually said and closes the occasion — or re-routes it |
| `RunProductionSlotsCommand` | `sanitube:production:run` — the production path |
| `production_slots.music_generation_id` | why this track exists, read from the other end |
| `production_slots.attempted_providers` | which suppliers this occasion has been offered to |

## Three claims, two of them negative

### 1. Starting is not producing

A request queued at a supplier leaves the occasion **CLAIMED**, never COMPLETED,
and creates no `Track`. Completing at the moment of asking would record that
something was made before anything was — which is what the whole slot lifecycle
exists to prevent. The audio takes minutes, arrives asynchronously, and can fail.

### 2. A supplier failing does not lose the occasion

A plan that wanted a track on the 14th still wants one. A failed generation is
re-offered to the next supplier that could serve it and has **not already been
tried**, and only an occasion nobody will take is failed. The attempted list is
written **before** each supplier is asked, so a crash mid-request cannot cause
the same one to be tried twice.

This is what GEN-006's `candidates(required:, except:)` was built for.

### 3. A refusal that resolves itself is a skip, not a failure

| Refusal | Outcome | Why |
| --- | --- | --- |
| `CEILING_REACHED` | SKIPPED | rolling window; makes room on its own |
| `PROVIDER_CIRCUIT_OPEN` | SKIPPED | closes itself after the cooldown |
| `PROVIDER_UNAVAILABLE` | FAILED | somebody has to configure it |
| `PROVIDER_INCAPABLE` | FAILED | somebody has to change supplier |
| `NO_CAPABLE_PROVIDER` | FAILED | somebody has to configure one |

A failure list full of ceilings is a failure list nobody reads.

**A ceiling is also not worth another supplier.** It belongs to the
installation, not to a provider, so moving the occasion elsewhere to get past it
would defeat it. The refusal stops the loop rather than advancing it, and a test
asserts that only one supplier was tried.

## Reconciliation, not an event

Generation results arrive by polling. A listener would only fire on the runs
that happened to be observed, and a slot whose generation failed while the queue
was down would sit CLAIMED for ever with nothing due to notice it. Reading the
current state of the row is the only approach that recovers by itself — and it
is the shape the distribution module already uses.

**Settling runs before producing** in the command, and the order matters: a bad
afternoon at one supplier is recovered in the same run rather than the next one,
and the inventory that decides whether more music is wanted then counts a run
that has already been reconciled.

## WHO CALLS THIS IN PRODUCTION?

```
sanitube:production:run                      (ProductionServiceProvider::boot)
  -> BackgroundWork::isPaused                (refuses first)
  -> SettleProductionSlot::handle            (per in-flight occasion)
       -> SelectGenerationProvider::candidates(except: slot->attempted())
       -> StartMusicGeneration::handle       (re-route)
  -> ProduceForProductionSlot::handle        (per due occasion)
       -> WorkProductionSlot::handle         (claim, plan, autonomy, inventory)
       -> StartMusicGeneration::handle
       -> SubmitMusicGenerationJob::dispatch
```

Not scheduled by default. `ProductionServiceProvider` already registers commands
and schedules nothing, and the argument is stronger here than anywhere: this is
the one command in the platform that pays a supplier with nobody present, and a
cron entry that quietly begins doing so is not something to install on an
operator's behalf. `--settle-only` exists for the operator who wants the
recovery loop running while they decide whether to trust the spending one.

## What it asks for

The plan's editorial profile, via `EditorialProfile::guidance()`, and nothing
invented in the producer. A prompt written there would be a second place an
installation's voice is decided, silently different from the one an operator can
see and edit — which is what EDI-001 exists to prevent. A profile that says
nothing useful yields its name, because a supplier handed an empty prompt
produces *something*, and something unasked-for is worse than a refusal.

A re-route reuses the previous generation's prompt rather than re-deriving it,
so an edit made to the profile in between cannot change what this occasion asked
for halfway through.

## What was deliberately not done

A **claim with no generation** — a worker that died between claiming and asking —
is left alone rather than failed. The occasion has not been spent, and failing
it would throw away work nobody did. Deciding when a claim is stale enough to
take back is a reaper, and it is a separate ticket. A test pins the current
behaviour so that ticket has something to change.

## Verification

- **22 tests, 132 assertions** in `tests/Feature/Production/ProductionSlotWorkTest.php`
- Full suite: **1865 passed, 1 skipped, 23665 assertions**
- PHPStan level 6 clean, Pint clean, vue-tsc clean, Vitest 23 passed
- `FinancialScopeTest` already sweeps `src/Production`; the new files are in scope and hold no money

### Mutation testing — 15 mutations, 14 killed, 1 equivalent

Killed: completing the slot on request (M1), no re-route (M2), re-route
forgetting what it tried (M3), a ceiling filed as a failure (M4), a ceiling sent
to a second supplier (M5), producing without the inventory (M6), settling an
in-flight generation (M7), failing a dead worker's claim (M8), skipping the
settle pass (M9), ignoring the global pause (M10), abandoning the editorial
prompt (M11), the claim not checking the due date (M12b), forgetting the
generation link (M13), asking a supplier before writing it down (M14).

**M12 survived and is equivalent.** It removes the command's
`whereDate('scheduled_for', …)` filter — and `ClaimProductionSlot::claim()`
already refuses a slot whose day has not come, which is what the command's own
comment says (*"this list is only a suggestion"*). M12b removes that real guard
and is killed. Recorded as equivalent rather than papered over with a test that
would assert an implementation detail.

## State

`CODE READY`. The engine now runs end to end **with the fake provider**. There
is still no real music provider on this installation, so nothing here is
`PRODUCTION VERIFIED` — what changed is that the planner is no longer a decision
with nothing on the other side of it.
