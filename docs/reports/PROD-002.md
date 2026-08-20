# PROD-002 — an occasion, and the fact that opening one is not doing it

## The scheduler must never invoke music generation

**The constraint the whole ticket is built around.**

A scheduler that generated directly would be a cron entry that spends money in
the dark. Nothing would record that the occasion existed. Nothing could cancel
it before it ran. A second run — an overlapping cron, an operator pressing the
button, a deploy restarting the process — would do the work twice. And the only
evidence afterwards would be a bill and some audio.

So the scheduler writes **a row** and stops. That single indirection is what
buys all four properties §17 asks for:

| Property | What provides it |
|---|---|
| **Idempotent** | the unique key on `(plan, date)` |
| **Traceable** | the row *is* the record |
| **Cancelable** | a person can settle it before or after anything claims it |
| **Auditable** | every transition is a state on a row, not a side effect |

Asserted two ways. `the_scheduler_never_reaches_generation` greps the scheduler
and its command for `MusicGeneration` — a blunt instrument, and exactly right,
because this boundary erodes by somebody adding one convenient call.
`opening_slots_queues_no_work_and_spends_nothing` asserts the behaviour, for a
plan set to the most autonomous mode that can exist.

## Idempotence is the database's job

Checking for an existing slot and then inserting is **"usually once"**: two
processes can both find nothing and both insert. The unique index settles it,
and the constraint violation is caught and treated as success — because it means
exactly what the scheduler wanted to be true.

`the_database_is_what_enforces_one_occasion_per_day` asserts the key exists
rather than trusting the service that respects it.

## Two tests that were not testing what they said

**The "race" test was testing the cadence.** It pre-created a slot and called
the scheduler — but the scheduler *sees* that slot when it works out the next
date, so it returned early and never reached the insert. The catch it claimed to
exercise was unreachable, and a mutation replacing `UniqueConstraintViolationException`
with `LogicException` survived. It is renamed to what it actually asserts (an
occasion that already exists is not opened again, which is worth asserting), and
a real race test now sits beside it: the conflicting row is inserted **from
inside** the model's `creating` event, with a raw statement, which is the only
way one thread can be in that window.

**And that real race test passed while proving nothing**, for a reason worth
recording:

> Laravel writes a `date` cast through the connection's datetime format, so an
> Eloquent insert stores `Y-m-d H:i:s`. **MySQL and MariaDB** coerce that to a
> DATE, and two writers disagreeing about the format still collide correctly.
> **SQLite does not**: its typing is dynamic, the column holds whatever string it
> was given, and a bare `Y-m-d` sits alongside an Eloquent row for the same day
> without tripping the unique key.

Everything in this application writes these rows through Eloquent, so the key
holds. It is recorded in the migration because the failure mode is invisible: a
raw insert in a future seeder or import would silently create the duplicate the
table exists to prevent — and only on the engine the test suite runs fastest on.

## A cadence is counted from the last occasion

Not from the plan's creation. A scheduler that answered "how many should there
have been by now" would turn a fortnight's pause into a fortnight's backlog. A
plan that fell behind catches up **one occasion at a time**.

**Slots are opened on the day, never ahead of it.** A horizon looks like a
kindness and is an off-by-one: a slot opened a day early is un-claimable anyway
— a worker checks the date — so it buys nothing, and it shifts every subsequent
occasion because the next is counted from this one's date. Written with a
one-day horizon first; the cadence test is what caught it.

## Claiming happens exactly once

A guarded `UPDATE`, never a read-then-write. Two workers draining the same queue
will both find the same pending slot, and only an atomic update decides which
gets it — atomic on every engine in the matrix, where read-then-write is not.
The loser of a real race holds a stale in-memory row saying `PENDING`, and
`a_stale_reader_does_not_win_the_claim` is written that way deliberately.

A slot scheduled for later is **not work waiting**. A worker that ignored the
date would do a month of production in an afternoon.

## Six states, and the two distinctions that matter

`PENDING` · `CLAIMED` · `COMPLETED` · `CANCELLED` · `SKIPPED` · `FAILED`

**`SKIPPED` is not `FAILED`.** An inventory that found enough already, or a plan
that reached its target between the slot opening and being claimed, is the
system working. A screen filing those under failures would teach an operator to
ignore the failure list — which is the list that matters.

**`CANCELLED` is the only one a person sets.** Somebody looking at a month of
slots needs to see which gaps were their own doing. And cancelling works from
`CLAIMED` as well as `PENDING`: a worker holding a slot and waiting on a
provider is exactly the situation somebody wants to call off, and a cancel that
only worked before anything picked it up would be a cancel that never works when
it matters.

A settled slot is not re-settled — that would rewrite how it actually ended.

## The command

`sanitube:production:open-slots` opens rows and nothing else. Safe to run twice
in a minute and safe to run hourly, which is what makes it a sane thing to put
in a cron entry an operator never looks at again.

It asks the **global stop** first: a scheduler that kept opening work during a
pause would hand the queue a backlog the moment somebody resumed, which is the
opposite of what pausing is for. It deliberately does **not** ask the backlog
ceiling — that guard is about queued jobs and this enqueues none, and refusing
to write a row because the queue is busy would lose the occasion rather than
defer it.

It says out loud that nothing was generated and nothing was spent, because
"opened 3 slots" reads as "made three tracks" to anybody who has not read the
scheduler.

The dry run **reports no count**, and says why. Reporting one would mean running
the scheduler and rolling back, and a rollback racing another process would
report a slot that then really existed.

**No framework scheduled task is registered**, deliberately. This platform's
baseline is a shared account whose cron entry an operator writes once, and a
`schedule:run`-dependent task would be a second place for the same decision to
live — silently doing nothing on exactly the installations that most need it
visible. The command is the interface; where it is called from is the operator's.

## Mutation record

Eighteen mutations, all killed.

| # | Mutation | Killed by |
|---|---|---|
| N1 | scheduler dispatches generation | `the_scheduler_never_reaches_generation` |
| N2 | unique key removed | `the_database_is_what_enforces_one_occasion_per_day` |
| N3 | scheduler ignores the last slot | `a_cadence_is_counted_from_the_last_occasion` |
| N4 | the race is not caught | `a_real_race_on_the_same_occasion…` |
| N5 | cadence counted from now | `a_cadence_is_counted_from_the_last_occasion` |
| N6 | every missed date opened at once | `a_plan_that_fell_behind_catches_up…` |
| N7 | opens ahead of the day | `a_cadence_is_counted_from_the_last_occasion` |
| N9 | inactive plans get slots | `only_an_active_plan_opens_occasions` |
| N10 | claim is read-then-write | `a_slot_is_claimed_exactly_once` |
| N11 | claim ignores the date | `a_slot_scheduled_for_later_is_not_work_waiting` |
| N12 | cancel refuses a claimed slot | `a_person_may_call_off_an_occasion…` |
| N13 | settled slots re-settle | `a_settled_occasion_is_not_re_settled` |
| N14 | skipped collapses into failed | `skipped_is_not_failed` |
| N15 | `isDue()` always true | `a_slot_scheduled_for_later_is_not_work_waiting` |
| N16 | command ignores the pause switch | `the_command_stops_when_the_platform_is_paused` |
| N17 | dry run opens slots | `a_dry_run_opens_nothing…` |

**N3 survived its first run against the idempotency test and is killed by the
cadence test** — a filter-scope artefact rather than a gap, and worth naming so
the table is not read as "covered by the test next to it".

**N8 is withdrawn, honestly.** Removing `whereNotNull('cadence_days')` from the
scheduler's query changes nothing an observer can see, because `openFor()`
refuses a null cadence anyway. It is a query narrowing rather than a guard — a
manual plan is a plan this scheduler has no business loading — and the code now
says so in a comment rather than being propped up by a test that only exists to
kill the mutation.

## Who calls this in production?

The console command, which an operator puts in cron. That is the production path
for opening occasions, and it is tested at that boundary.

**Nothing claims a slot yet**, and that is the honest limit of this ticket. The
claim service, the states and the refusals are real and tested; the worker that
takes a slot and produces something is the next ticket, and it is the one that
has to check inventory first (§18) before it generates anything. Wiring a
claimer here would have meant shipping the generation decision in the same
change as the scheduling primitive it depends on.
