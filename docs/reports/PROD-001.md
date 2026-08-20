# PROD-001 — a body of work, and how far the platform may carry it alone

## Unattended release is locked, and not by a setting

**The most important refusal in the platform.**

Handing a release to a distributor is the one operation SaniTube has that
SaniTube cannot undo. Shops, stores and aggregators hold the result; a takedown
is a *request* rather than a delete; a delivered identifier is spent. Doing that
without a person is not a feature with a switch missing — **it is a decision
nobody has made.**

So `AUTONOMOUS_RELEASE` exists as a case and cannot be chosen. The case is there
precisely so the absence of the decision is visible in the type rather than
implied by there being no code for it.

The lock is **in code, not in configuration.** A locked mode an environment
variable unlocks is locked in name only, and `no_setting_unlocks_unattended_
release` sets three plausible flags and asserts none of them changes the answer.
When somebody does decide, they will edit one method, and that edit is a
reviewable line in a diff.

Three tests hold it from three directions:

- **The writer refuses**, rather than quietly lowering the request. That is the
  worse failure of the two: an operator who asked for unattended release and
  silently got `ASSISTED` believes the platform is doing something it is not,
  and finds out at the point where a release did not go out — or did.
- **No plan that can exist may release unattended**, asked of every settable
  mode, so the answer is "there is no state in which this is true" rather than
  "we have not built the caller".
- **A row holding the locked mode still releases nothing.** Written around the
  service on purpose: a seeder, a migration, a raw `UPDATE` or a restored backup
  can put the value in the column without ever passing the code that refuses it,
  and the answer must still be no. A mutation removing that second check
  survives every test that goes through the writer — which is how the gap was
  found.

## Autonomy belongs to the plan

Not to an artist, which is a public credit. Not to an editorial profile, which
is taste. **How much a machine may do unattended is a decision about this body
of work.**

The same artist can appear on one plan a person drives by hand and another that
generates unattended; an imprint's taste and its appetite for automation are
different questions. A column on either table would make those the same setting.

`autonomy_is_not_a_column_on_an_artist_or_an_imprint` asserts it **against the
schema**, which is where a well-meaning migration would put it, and a mutation
that adds the column to `artists` dies against it.

## The ladder

Each rung adds exactly one thing the platform may do without being asked. Read
as "the platform may go this far by itself, and stops".

| Mode | Adds |
|---|---|
| `MANUAL` | nothing; every step is a person |
| `ASSISTED` | prepare and propose; a person decides |
| `AUTONOMOUS_GENERATION` | generate audio unattended |
| `AUTONOMOUS_PREPARATION` | assemble a release and stop |
| `AUTONOMOUS_RELEASE` | **locked** |

`AUTONOMOUS_GENERATION` is a separate rung from `ASSISTED` because it is the
first that spends money without being asked each time. And **preparing is not
delivering**: a release assembled unattended still sits there until a person
sends it.

## Status and mode are both required

`mayGenerateUnattended()` asks the status *and* the mode. A caller that checked
only the mode would happily generate under a plan somebody stopped an hour ago —
and a mutation dropping the status check dies against exactly that test.

## Five states, and why each is separate

- **`ACTIVE`** — the only runnable one. Anything the platform is unsure about
  should stop it acting, not merely be recorded beside it acting.
- **`PAUSED`** — *a person* stopped it. Resumes where it was.
- **`EXHAUSTED`** — the plan did what it set out to do. Not a failure and not a
  stop somebody chose; the remedy is to raise the target, extend it, or finish.
- **`REVIEW_REQUIRED`** — *the platform* stopped itself and wants somebody.
- **`DISABLED`** — switched off indefinitely, by a person. Kept rather than
  deleted: work done under it still refers to it.

**`REVIEW_REQUIRED` is the state that makes autonomy safe to switch on**, and
its distinction from `PAUSED` is the one that matters most. Paused means a
person stopped it; review-required means the platform did. Collapsing the two
would hide every self-stop inside a state that looks deliberate — an operator
scanning a list would see "somebody paused these" and move on.

`wasSetByThePlatform()` is what a screen groups by, and `halted_reason` /
`halted_at` are filled **only** on a self-stop. A person's reason belongs in the
audit line that records their act, not in a column the plan overwrites on its
next self-stop.

Resuming works from `PAUSED` and `REVIEW_REQUIRED` and refuses from the other
two: an exhausted plan needs its target raised and a disabled one needs
reconsidering, and neither is a thing a resume button should do silently on
somebody's behalf.

## A plan is an intention, not a schedule

`cadence_days` says how often the plan expects to produce something. When a
particular piece of work actually happens is a **slot's** business, and the
separation is what keeps a plan from becoming a cron entry with opinions.

`target_track_count` is recorded and never enforced by this table — it is what
somebody set out to make, so "we wanted forty and got thirty-one" is answerable.
What actually stops a plan is its status, and reaching the target is one of the
things that sets it.

A new plan is `ACTIVE` and `MANUAL`. Active because a plan that arrived paused
is a plan somebody has to remember to start, which is how a body of work quietly
never happens; manual because the safe default for "how much may the machine do
alone" is none.

## Mutation record

Seventeen mutations, all killed.

| # | Mutation | Killed by |
|---|---|---|
| L1 | unattended release becomes available | `unattended_release_cannot_be_chosen` |
| L2 | `available()` offers the locked mode | same |
| L3 | availability driven by configuration | `no_setting_unlocks_unattended_release` |
| L4 | writer quietly lowers a locked mode | `the_locked_mode_is_refused_rather_than_quietly_lowered` |
| L5 | `mayReleaseUnattended()` ignores availability | `a_row_holding_the_locked_mode_still_releases_nothing` |
| L6 | preparation may also release | `the_ladder_adds_exactly_one_permission_at_a_time` |
| L7 | status ignored when generating | `a_paused_plan_generates_nothing…` |
| L8 | paused counts as a self-stop | `a_plan_the_platform_stopped_is_distinguishable…` |
| L9 | pause records a halt reason | same |
| L10 | exhausted is resumable | `a_stopped_plan_resumes_and_a_finished_one_does_not` |
| L11 | resume does not clear the halt | same |
| L12 | review-required is runnable | `only_an_active_plan_runs` |
| L13 | a new plan defaults to autonomous | `a_plan_defaults_to_doing_nothing_on_its_own` |
| L14 | a retired imprint takes plans | `a_retired_imprint_takes_no_new_plans` |
| L15 | nonsense numbers stored | `nonsense_numbers_become_nothing…` |
| L16 | autonomy column added to `artists` | `autonomy_is_not_a_column_on_an_artist_or_an_imprint` |

**L5 survived its first pass**, and the resolution was a new test rather than an
adjustment. Removing the availability check from `mayReleaseUnattended()`
changes nothing for any mode the writer can produce — the second check only
matters when the row got there another way. A seeder, a migration or a restored
backup is exactly that path, so the test writes the row directly and asserts the
answer is still no.

## Who calls this in production?

**Nothing yet, and the report says so.** A plan is a standing intention; what
turns one into work is a slot, and that is PROD-002. Registering a scheduler
here would be a scheduler with nothing to schedule.

What is real and testable today is the shape of the decision: the modes, the
lock, the states, and the rule that autonomy is a property of a body of work.
Those are what every later ticket in this area has to obey, and they are worth
having settled — and pinned by mutation — before anything starts acting on them.

## Not in scope

No HTTP surface, no screen, no scheduler. `Slot` does not exist; neither does
the inventory check that §18 puts before generation, and the report for that
ticket will be the one that can say a plan actually caused something.
