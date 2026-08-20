# PROD-UI-001 — watching, and stopping, the part that acts alone

PROD-001 through PROD-004 built the one thing in SaniTube that runs with nobody
present: it decides that more music should exist, chooses a supplier, and pays
for it. **Until this ticket the only way to see or stop any of that was a
shell** — `artisan` and a database client.

That made the most consequential subsystem in the product the one an operator
could not watch. `ls resources/js/Pages/` had no `Production`, and
`grep production src/Ui/Routes/web.php` returned nothing.

---

## What was built

| Piece | What it is |
| --- | --- |
| `GET /production` | every plan, its autonomy, and every outcome its occasions reached |
| `GET /production/plans/{plan}` | one plan and its occasions — *why nothing happened on the 14th* |
| `POST …/pause`, `…/resume` | stopping, which must always work |
| `POST …/autonomy` | granting or withdrawing the licence to act alone |
| `POST /production/occasions/{slot}/cancel` | calling off one occasion |
| `ProductionPlanIndexQuery`, `ProductionPlanDetailQuery` | the read models |

## Three claims

### 1. Stopping always works, and does not need a shell

A pause is allowed from **every** unsettled state, including one the platform
set itself. A pause button that refused because the plan was already stopped
would make somebody work out *which kind* of stopped it was before they could be
sure it would not start again.

Cancelling an occasion is allowed from PENDING **and** CLAIMED — the rule
`ClaimProductionSlot` already had: a worker waiting on a supplier is exactly the
situation somebody wants to call off, and a cancel that only worked before
anything picked it up would be one that never works when it matters.

### 2. No supplier credential reaches the browser

An occasion carries a generation; a generation carries a `provider_job_id`, and
on a query-string-authenticated provider that string **is** a bearer credential.
Neither it nor the generation's `prompt` or `lyrics` cross to the screen. A test
reads the whole rendered body and asserts none of the three appear.

What does travel is the generation's uuid and state — exactly enough to open the
studio screen that owns it — and `attempted_providers`, because which suppliers
were asked is operational fact and the names are already in the operator's own
configuration. No endpoint and no key travel with them.

### 3. Autonomy and running are two different questions

A plan can be granted the right to act alone **and** be paused. It can also be
running and not permitted to do anything alone — which is most plans. They are
separate fields, separate columns, and separate sentences, because an operator
looking at a quiet month needs to know which of the two it is.

Skips and failures are likewise kept apart, in both counts and wording. An
inventory that found enough is the system working; a screen that merged them
would teach somebody to ignore the failure count.

## WHO CALLS THIS IN PRODUCTION?

```
GET  /production                      -> PlanIndexController  -> ProductionPlanIndexQuery
GET  /production/plans/{plan}         -> PlanDetailController -> ProductionPlanDetailQuery
POST /production/plans/{plan}/pause   -> PlanActionController::pause      (can.role:catalogue)
POST /production/plans/{plan}/resume  -> PlanActionController::resume     (can.role:catalogue)
POST /production/plans/{plan}/autonomy-> PlanActionController::setAutonomy(can.role:catalogue)
POST /production/occasions/{slot}/cancel -> …::cancelOccasion             (can.role:catalogue)
NavigationTree                        -> '/production', available to every signed-in user
```

Reads are open to anybody who may sign in. Watching what the platform did on its
own is not a privilege; being unable to is a hazard. Writes are behind
`can.role:catalogue` **on the route**, so a future action cannot acquire the
surface without acquiring the guard — a hidden button has never been an
authorisation, and a test posts every write as a MEMBER and asserts 403.

## Audited, because a plan row holds only its current state

Four new actions — `production.plan.paused`, `production.plan.resumed`,
`production.autonomy.changed`, `production.occasion.cancelled` — and two new
subjects. The autonomy change records **what it was changed to** in its context:
"who changed the autonomy" without "to what" is half an answer.

The occasion is the subject of a cancellation, never the plan: a plan outlives
many cancelled occasions, and recording the plan would make "which one did they
call off" unanswerable.

## The locked mode is offered and then refused

`AUTONOMOUS_RELEASE` is listed in the form and validated as a real mode. Hiding
it would make the platform look as though it could release on its own and simply
had not been asked to; refusing it in validation would say "that is not a mode",
which is false. Refusing it in the service says *"that mode is not available
yet"*, which is true — and that is the sentence the screen shows.

## Verification

- **20 tests, 384 assertions** in `tests/Feature/Ui/ProductionScreensTest.php`
- Full suite: **1885 passed, 1 skipped, 24623 assertions**
- PHPStan level 6 clean, Pint clean, vue-tsc clean, Vitest 23 passed, build OK
- Every plan state, occasion state, autonomy mode, outcome reason and refusal
  code is translated in all six languages, asserted by walking the enums

### Mutation testing — 16 mutations, 16 killed

Killed: leaking the provider job id and prompt (M1), dropping the zero counts
(M2), `may_steer` always true (M3), removing the route guard (M4), a skip not
saying it was one (M5), hiding which suppliers were asked (M6), a cancel button
on a finished occasion (M8), reporting a refused cancel as success (M9), not
auditing a pause (M10), auditing an autonomy change without the mode (M11),
unlinking the screen from the navigation (M12), folding autonomy into running
(M13), reversing the occasion ordering (M14), a pause button that does nothing
(M15), swallowing a refusal (M16), forcing every change to MANUAL (M17),
accepting a foreign cursor (M18).

**Two rounds, and the first produced a false survivor worth recording.**

M2 was first reported as SURVIVED. It was not: applied by hand, the test failed
immediately. The cause was in the harness, and it is exactly the failure mode the
standing instruction names. The invocation contained

```
'        $counts = [' "'"'total'"'"' => 0];
```

— with a **space** before `"'"`. Bash concatenates adjacent quoted strings only
when there is no space between them, so what I had written as one argument
became three. The replace text became the search, the *filter* argument became a
line of PHP, and a filter matching no test makes the runner exit 0. A changed
file plus a zero exit reads as a survivor.

Two fixes, both in the harness rather than in my care:

1. **Search and replace text now come from files**, never from shell arguments.
2. **A run whose filter matched no tests is `NO_TESTS_RAN`, not a survivor.** The
   guard was proved by deliberately re-running M2 with the bad filter: it now
   reports the harness bug instead of a false result.

M8 and M13 were genuine survivors and are now covered. M13 is the interesting
one: my autonomy test used a *paused autonomous* plan, where "may act alone" and
"is running" happen to agree, so replacing one with the other changed nothing.
The distinguishing case — a **running plan nobody granted autonomy to**, which
is most plans — is now its own test.

## State

`CODE READY`, and this one is closer to usable than most: it needs no supplier,
no credential and no external service. On an installation with a real music
provider it is the screen that makes the autonomous engine safe to switch on.
