# ADR-0007 — Artist credits are canonical relations, never columns

- **Status:** Accepted
- **Date:** 2026-08-14
- **Ticket:** ARCH-002

## Context

Every catalogue needs to answer "who is this by?", and the obvious schema is a
`primary_artist_id` on `tracks` and on `releases`. It makes the common listing
a single join and reads naturally.

It is also wrong for this catalogue, for a reason that is not an edge case:
collaborations are ordinary. Two artists sharing top billing is a normal
release, and a single-artist column forces one of two equal credits to be
demoted to fit the schema. The demoted one then has to be recovered from the
relation table anyway, so every consumer ends up joining regardless — while
still having to decide whether the column or the relation is authoritative when
they disagree. And they do disagree, because one of them is maintained by hand.

The same argument applies to a `display_artist` string on `release_tracks`.

## Decision

`track_artist` and `release_artist` are the only sources of truth for artist
credits. Both allow several `PRIMARY` rows. There is no `primary_artist_id`, no
`display_artist`, no `artist_display_name` anywhere in the schema.

Readiness requires *at least one* `PRIMARY` credit (I3, I4) rather than exactly
one.

A test asserts that none of those columns exists on `tracks`, `releases` or
`release_tracks`, so the decision cannot be quietly undone by a later migration.

## Consequences

**Accepted:**

- Collaborations are representable without a demoted credit.
- One answer to "who is this by?", and it cannot drift.
- Ordering is explicit through `position` rather than implied by which artist
  won the column.

**Costs:**

- Listing tracks with their artists is a join and an eager load, not a column
  read. On a catalogue of this size that is unremarkable; at a scale where it
  stops being unremarkable, ADR-0011 applies.
- Formatting a credit string ("A feat. B") is application logic rather than a
  stored value.

## Revisit when

Never as stated — a canonical relation is not something to move away from. The
performance concern it raises is answered by ADR-0011, which adds a derived
projection *alongside* the relation rather than replacing it.
