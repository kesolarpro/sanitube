# ADR-0011 — Read projections deferred; canonical relations first

- **Status:** Deferred — revisited when profiling justifies it
- **Date:** 2026-08-14
- **Ticket:** ARCH-002

## Context

ADR-0007 makes artist credits canonical relations with no denormalised column.
The cost is real and worth stating plainly: listing tracks or releases by
artist becomes a join with an aggregate rather than a column read, and a
"browse by artist" screen over a catalogue of this size will do measurably more
work than it would with `primary_artist_id`.

A denormalised display field — `track_display_artist`, or `display_artist` on
`release_tracks` — would answer that. It is a legitimate technique and it does
not have to create a second source of truth, provided it is *derived* rather
than *maintained*.

The distinction is everything. A projection written by a rebuild job, from the
canonical relations, is a cache: when it disagrees with the relations, the
relations win and the projection is rebuilt. A column written by hand at the
same time as the relations is a second truth: when they disagree, nobody knows
which is right.

## Decision

**No projection in ARCH-002.** Not `artist_display_name`, not
`track_display_artist`, not `release_display_artist`, and `display_artist` is
removed from `release_tracks`.

The reason for waiting is not doctrine, it is evidence: there is no profiling
data yet, and no UI to profile. A projection added now would be shaped by a
guess about which query matters, and would then be maintained forever.

When one is added, it must be:

- **derived** — written only by a rebuild path reading the canonical relations;
- **rebuildable** — a command can drop and regenerate it from scratch;
- **non-authoritative** — documented and named such that no consumer treats it
  as the source of truth;
- **verifiable** — a test proves rebuilding produces what the relations say.

## Consequences

**Accepted:**

- One source of truth for artist credits throughout ARCH-002 and everything
  built on it.
- No projection to keep in step while the model is still moving.

**Costs:**

- Artist-facing listings are slower than they would be with a column, and will
  need eager loading to avoid N+1 queries. Accepted for now, and cheap to fix
  later precisely because the canonical data is correct.
- Someone will propose `primary_artist_id` again. A test asserting the column's
  absence, plus ADR-0007, is the answer.

## Revisit when

A real screen over a realistic catalogue is measurably too slow, and the
profile points at this join. Then add the projection under the four rules
above — alongside the relations, never instead of them.
