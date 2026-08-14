# ADR-0008 — A track's master and a release's cover are foreign keys

- **Status:** Accepted
- **Date:** 2026-08-14
- **Ticket:** ARCH-002

## Context

Both facts could be expressed two ways: a foreign key on the owner
(`tracks.master_asset_id`, `releases.cover_asset_id`), or a row in the
polymorphic `asset_links` table with role `MASTER` or `COVER`.

Supporting both is superficially attractive — one uniform way to attach any
asset to anything — and it is exactly the trap. Two representations of one
single-valued fact will eventually disagree: an import writes the link, an
editor writes the column, and afterwards there is no principled way to decide
which is true. For a master, "which file is this recording?" is the single most
consequential question the catalogue answers.

## Decision

- A track's master is **only** `tracks.master_asset_id`.
- A release's cover is **only** `releases.cover_asset_id`.
- `AssetLinkRole` has no `MASTER` and no `COVER` case, so the alternative is
  not merely discouraged, it is unrepresentable.

Both foreign keys use `ON DELETE RESTRICT`: removing an asset that a track
calls its master must fail loudly.

## Consequences

**Accepted:**

- One place to read, one place to write, nothing to reconcile.
- A track has at most one master by construction, with no uniqueness rule to
  enforce over a link table.
- Readiness can check the master's kind and status directly (I3, I4).

**Costs:**

- Two special cases in a schema that is otherwise uniformly polymorphic. This
  ADR is the explanation.
- If a track ever legitimately needs several masters — alternate territorial
  versions, say — this becomes a migration rather than a new row. That is the
  right trade: the migration is a deliberate act, and the ambiguity it would
  replace is permanent.

## Revisit when

A genuine multi-master requirement appears — territorial variants or a
versioned master lineage. The replacement must still have exactly one
authoritative answer per context, not a link table where any row might be it.
