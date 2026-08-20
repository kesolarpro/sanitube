# DEDUP-003 — the screen a person answers findings on

## Where this sits

DEDUP-001 records findings; DEDUP-002 gave a person the means to answer one and
to set a master aside. Neither was reachable over HTTP. This is the queue, and
it closes Phase 3.

## Two buttons, never one

Confirming says the platform was right. Setting a master aside is a separate
act, with its own reason and its own audit line, and they are separate routes —
`POST /duplicates/{relation}/confirm` and `POST /assets/{asset}/trash`.

An interface that did both in one request would turn an agreement into a
deletion, which is exactly what this feature must never become. The test asserts
it at the HTTP boundary: `confirming_a_finding_does_not_discard_anything`.

Trash is keyed by the **asset**, not by the finding. A master is set aside
because somebody decided to set *it* aside; routing that through a finding would
tie the act to the row that prompted it and leave the same file untrashable from
anywhere else.

## Who may press what

Reading the queue needs only a signed-in account — knowing two files may hold
the same recording is not a privilege. Every action is behind
`can.role:catalogue`, the same guard candidate review uses, and the check is on
the route so a future action cannot acquire the endpoint without the guard.

`mayDecide` in the page hides the buttons. That is presentation; a hidden button
is not a permission check, and `a_member_may_read_the_queue_and_decide_nothing_in_it`
proves the routes refuse a MEMBER regardless of what the markup renders.

## The reason is a code from a closed list

`DUPLICATE`, `SUPERSEDED`, `WRONG_UPLOAD`, `UNUSABLE_AUDIO`, `OTHER`. Never free
text: the column outlives whatever English was current, an operator filtering a
year of trash needs values that group, and a reason somebody typed is a reason
nobody can count. The picker is fed from the same constant the validator
enforces, so the two cannot drift.

## A finding — the leak test was defective, and a mutation caught it

The first version of `the_payload_carries_the_evidence_and_never_where_a_master_lives`
read the rendered HTML and asserted the object path was absent. **It passed with
the path deliberately leaked into the payload.**

Inertia embeds its props as JSON, and `json_encode` escapes `/` as `\/`. The raw
path therefore never matched the page source whether or not it was there. The
assertion named a real property and tested nothing.

It now asserts against the query's own output with `JSON_UNESCAPED_SLASHES`, and
three separate mutations — leaking `path`, restoring `original_filename`, and
sending the full digest instead of twelve characters — each fail it.

## A second finding — the payload was widening an explicit boundary

Writing that test properly surfaced the real problem underneath it.
`AssetIndexQuery` states in its own docblock that **nothing about where an asset
lives leaves that class: no disk, no path, no bucket, no original filename**, and
`CatalogAssetsTest` enforces it. The first draft of `DuplicateReviewQuery` sent
`original_filename` for both sides of every finding.

The filename is on that list because it is the field that most looks like a title
and is not one — ING-001 settled that a filename is metadata and never identity.
A reviewer choosing which copy to keep by its name is deciding on the least
reliable thing about it.

**The code changed, not the rule.** What each side now carries is what actually
distinguishes two files: the checksum prefix, the type, the size, the length, and
which arrived first. For an exact duplicate the bytes are identical by
definition, so arrival order *is* the answer — and it is the one fact a filename
would have obscured.

## The queue does not reorder itself

Ordered by `(detected_at, uuid)` and nothing else. A queue that promotes the
strongest evidence means the position of a row is different every time it loads,
and a reviewer working through a few hundred findings needs the list to stay
where they left it. The level is a filter instead.

Both sides of every finding are sent together with their evidence, because a row
saying only "these two match" costs two more page loads per decision, and a queue
that expensive is one nobody works through.

## Mutation testing

| Mutation | Test that failed |
|---|---|
| role guard removed from the actions | `a_member_may_read_the_queue_and_decide_nothing_in_it` |
| trash reason accepted as free text | `a_reason_outside_the_list_is_refused` |
| object path leaked into the payload | `the_payload_carries_the_evidence_and_never_where_a_master_lives` |
| `original_filename` restored to the payload | same |
| full digest sent instead of twelve characters | same |

## Not in this ticket

- **A finding detail screen.** Everything needed to decide is on the row; a
  detail page would be a second place for the same four numbers.
- **Bulk answering.** A "confirm all exact duplicates" button is the shape of
  control that makes ADR-0016's reasoning moot, and it needs its own decision.
- **Backfilling.** Evaluating an existing library on a schedule still needs the
  backlog circuit breaker.
- **Permanent deletion.** Still not implemented, and still not deferred for time
  — see DEDUP-002.

## Gate

1305 tests · 6138 assertions · 1 skipped · PHPStan 6 clean, no baseline · Pint
clean · vue-tsc clean · Vitest 23 passed · frontend build ok · all six locales
key-identical.
