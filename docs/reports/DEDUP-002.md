# DEDUP-002 — answering a finding, and setting a master aside

## Where this sits

DEDUP-001 could only ever write a row. This is the half that can do damage: it
lets a person answer a proposal, and it lets a master be hidden. So most of what
follows is about what stays true afterwards.

## Trash is three columns, not a status

`AssetStatus` describes what is known about the **bytes** — pending, stored,
verified, missing, quarantined — and a trashed master is still stored and still
verified. Folding "somebody set this aside" into that enum would make
`isImmutable()` answer a question it was not asked, and would discard the
verification state at the moment of trashing, so restoring would mean re-reading
the object to learn what it already knew.

So: `trashed_at`, `trashed_by_user_id`, `trash_reason`. All nullable — which is
also what keeps the migration portable, because a NOT NULL timestamp added after
`stored_at` would inherit MySQL's invalid implicit default (ADR-0017, learned one
ticket ago).

**Nothing is deleted.** The object stays, the checksum still describes it, the
fingerprint still matches it, and restoring clears three columns.
`trashing_hides_an_asset_without_touching_its_bytes` asserts the bytes and the
checksum directly through the storage provider, not just the row — if that ever
stops holding, this feature has become a delete with a friendlier name.

### Permanent deletion is not implemented, and that is the decision

It would need its own policy: a minimum age, a dry run, an explicit confirmation
naming what is about to go, and a guarantee that the object being addressed is
the one that was reviewed. None of that is cheaper than leaving the bytes alone,
and disk is the cheapest thing in this system to be wrong about (ADR-0015 reached
the same conclusion about re-import amplification).

## The refusals

| Refusal | Why |
|---|---|
| `ASSET_IN_USE` | A track master or a release cover. Tidying must not be able to break the catalogue. |
| `ASSET_NOT_SETTLED` | A PENDING asset is an upload in flight; trashing one races the write. The staging sweep owns that case. |
| `ASSET_ALREADY_TRASHED` / `ASSET_NOT_TRASHED` | So a double-submitted form does not rewrite who and when. |

Only the two references that would leave the catalogue *broken* count as in use.
An ingestion item or a candidate pointing at a trashed asset is a historical
record of an import, and history is supposed to mention things that were later
set aside.

## Confirming is not trashing

The separation the whole design rests on, and it is asserted directly:
`confirming_a_duplicate_does_not_trash_anything`.

Agreeing that two files hold the same recording is not a decision to lose one of
them. Keeping the two acts apart means an over-eager confirmation costs nothing,
which is what makes the queue safe to work through quickly — and it means **no
similarity score ever reaches `TrashAsset`**. There is no threshold, however
high, that sets an asset aside on its own. The only thing a finding contributes
is that somebody was shown a screen.

A reviewer may change their mind — nothing irreversible happened — but repeating
the same answer is refused rather than quietly re-saved.

## Both outcomes are audited

`asset.trashed` and `asset.restored` because trashing is the closest thing the
platform has to losing a master, even though it loses nothing; "where did that
file go" needs a name, a time and a reason code.

`duplicate.confirmed` and `duplicate.rejected` because **the rejection is the
more interesting line**: it is a person saying the platform was wrong, and a run
of them is how a badly set threshold announces itself. `AuditSubject` gains
`duplicate_relation` rather than filing these under `asset` — a finding is its
own thing with its own uuid, which is the rule that enum already states.

## What a trashed asset stops doing

- It **drops out of the asset list**, and the list is still reachable with
  `trashed=only` — an asset nobody can find is one nobody can restore.
- It **stops producing findings, in both directions**: nothing is proposed about
  it, and it is not proposed as a match for anything else. Re-running the nightly
  evaluation must not ask a reviewer to answer a question they have answered.

## Mutation testing

Every claim inverted; one named test failed each time.

| Mutation | Test that failed |
|---|---|
| catalogue in-use guard removed | `a_master_the_catalogue_depends_on_cannot_be_trashed` |
| pending assets made trashable | `an_upload_still_in_flight_is_not_a_reviewers_to_trash` |
| restore leaves `trash_reason` behind | `restoring_returns_the_asset_exactly_as_it_was` |
| the same decision re-recordable | `the_same_answer_twice_is_refused_rather_than_re_recorded` |
| trashed assets still proposed | `a_trashed_asset_stops_producing_findings_in_both_directions` |
| listing stops hiding the trash | `a_trashed_asset_drops_out_of_the_list_but_stays_reachable` |

## Not in this ticket

- **The review screen.** The domain is complete and audited; the Inertia page,
  its controller and its routes are DEDUP-003. Nothing here is reachable over
  HTTP yet, which is deliberate — the destructive-looking half was worth landing
  with its refusals proven before anything could call it.
- **Permanent deletion.** See above. Not deferred for time.
- **Backfilling.** Evaluating an existing library on a schedule needs the backlog
  circuit breaker.

## Gate

1291 tests · 6037 assertions · 1 skipped · PHPStan 6 clean, no baseline · Pint
clean.
