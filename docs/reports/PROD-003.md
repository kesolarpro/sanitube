# PROD-003 — do not generate blindly

## The count that stops a plan double-ordering

Autonomous generation without an inventory is a machine that produces forty
tracks because forty is the number in the plan — regardless of the thirty-eight
already sitting unreleased. Every one of those forty is a paid call to somebody
else's server.

So the worker that takes an occasion **counts first**, and the order is fixed:

1. claim the occasion, atomically, or stop;
2. re-check the plan, because a queue is a delay;
3. **count what the plan already has**;
4. skip, with a reason, unless more is genuinely wanted.

## In-flight is the half everyone forgets

Work asked for and not yet finished has **nothing to show for it**. A count that
looked only at what exists would order more every single time it ran — turning a
plan that wants forty tracks into one that has ordered four hundred, all
correctly, one slot at a time.

Three sources, because work is in flight in three different places:

- generations a provider has not answered yet;
- candidates waiting for a person to promote them;
- slots open or claimed, which are occasions already committed.

`work_already_asked_for_is_counted` opens three slots against a target of three
and asserts the plan then wants nothing.

The mirror matters too: a **settled** occasion stops being in flight, or a plan
whose slots were all cancelled would never produce again.

## Unknown is not zero

**The most important refusal here.**

A plan's stock is counted through its editorial profile's default artist — the
only attribution the platform currently has, and a real one: a plan produces for
an imprint, and an imprint releases under a name.

A profile that names no artist has nothing to count through. Reporting **zero**
would be catastrophic and quiet: zero invites generating, so an installation
that cannot tell what it has would produce forever. So the inventory carries
`attributable`, `needed()` returns **null** rather than a number, and the
refusal is `NO_ATTRIBUTION` — asked *before* every other check, because every
other answer is a number this one says is meaningless.

## What counts as on hand

Draft or ready, **not on a release**, and credited to the imprint's artist as
**primary**.

Three filters, and two of them were only tested properly after a mutation
survived:

- **A released track is spent.** It did its job. Counting it would make a plan
  stop producing because it *once* had enough rather than because it *has*
  enough — and a plan that released everything would never produce again.
- **A draft already committed to a release is not stock.** This is the case the
  release filter actually guards, and the one the status filter does not. My
  test attached a *released* track, which the status filter excluded anyway, so
  it passed while proving nothing about that filter. A mutation showed it.
- **A featured credit is not this imprint's stock.** A guest appearance on
  somebody else's track is not something this plan can release, and counting it
  would make an imprint look like it had an album's worth of material it does
  not own the primary credit for. Also untested until a mutation said so.

`only_tracks_credited_to_this_imprints_artist_count` guards the other direction:
a count across the whole catalogue would let one imprint's stock satisfy
another's target, which is how two plans end up sharing a backlog neither can
see.

## The arithmetic, and two asymmetries

`needed = max(0, target − onHand − inFlight)`.

**A plan that overshot is not owed negative work** — hence the floor.

**`targetReached()` counts `onHand` only.** Work in flight might fail, and
marking a plan finished on the strength of it would leave a target the plan then
never reaches with nothing running to reach it. So "enough is coming" is
`ENOUGH_IN_FLIGHT` — a skip — and only "enough exists" is `TARGET_REACHED`.

**A plan with no target never wants more.** Null target means null *need*, not
unlimited need: an unstated intention is not a licence to produce indefinitely,
and a plan that wants unattended generation can say how much it wants.

## The worker decides; it does not do

`WorkProductionSlot` establishes that generating is warranted and returns the
count that justified it. **It generates nothing**, asserted by a grep for
`MusicGeneration` and `dispatch` in its own source.

Keeping the decision separate from the doing is what makes "why did the platform
make this" answerable from a row rather than from a log — and the slot stays
`CLAIMED` rather than `COMPLETED`, because completing it here would record that
something was made before anything was.

Two re-checks happen after the claim, because a queue is a delay:

- **the plan may have stopped** — paused, exhausted, or halted by the platform;
- **autonomy may not be granted** — the occasion is still real and a person can
  act on it, so it is *skipped* rather than failed.

Every refusal is a **skip with a reason**, never a failure. An inventory that
found enough is the system working, and a screen filing that under failures
would teach an operator to ignore the failure list.

## Mutation record

Nineteen mutations, all killed.

| # | Mutation | Killed by |
|---|---|---|
| P1 | in-flight not subtracted | `work_already_asked_for_is_counted` |
| P2 | committed slots not counted | same |
| P3 | settled slots still in flight | `a_settled_occasion_stops_being_in_flight` |
| P4 | released and committed tracks counted | `unreleased_tracks_count_and_released_ones_do_not` |
| P4b | spent tracks counted by status | same |
| P5 | whole catalogue counted | `only_tracks_credited_to_this_imprints_artist_count` |
| P6 | unknown reported as zero | `a_plan_whose_imprint_names_no_artist…` |
| P7 | unknown ranked below no-target | `not_knowing_outranks_every_other_answer` |
| P8 | no target means unlimited need | `a_plan_with_no_target_never_wants_more_on_its_own` |
| P9 | `needed` can go negative | `a_plan_that_overshot_is_not_owed_negative_work` |
| P10 | target reached counts in-flight | `the_target_is_reached_by_what_exists…` |
| P11 | worker skips the inventory | `the_worker_skips_rather_than_generating…` |
| P12 | worker ignores plan status | `a_plan_stopped_while_the_slot_waited…` |
| P13 | worker ignores autonomy | `a_worker_will_not_act_alone…` |
| P14 | worker completes instead of leaving claimed | `the_worker_counts_before_it_decides_anything` |
| P15 | worker fails instead of skipping | `the_worker_skips_rather_than_generating…` |
| P16 | worker does not claim | `two_workers_cannot_both_be_justified…` |
| P17 | non-primary credits counted | `a_featured_credit_is_not_this_imprints_stock` |

**Two survivors, both my fixtures rather than the code.** P4's test used a
released track where the meaningful case is a *draft* one already on a release;
P17's fixtures were all primary credits, so the role filter was never exercised.
Both are now tested for what they actually guard.

## An honest limitation

**Two plans on one imprint share stock.** That is correct for two campaigns
producing for the same imprint and wrong the moment they are meant to be
independent, and the platform currently cannot tell the difference — nothing
records that a particular asset came from a particular plan.

When a worker starts attributing individual assets to individual plans,
`TakeProductionInventory` is the class that has to read that attribution. It is
said here rather than left to be discovered by an imprint whose second plan
never produces anything.

## Who calls this in production?

`WorkProductionSlot`, and nothing calls that yet. The console command that
drains pending slots is the next step, and it is small — but it is also the
first thing in the platform that can spend money unattended, so it belongs with
the generation call it will make rather than ahead of it.

What is real today: the count, the refusals, the ordering, and the fact that the
decision is separate from the doing. Every one of those is pinned by a mutation.
