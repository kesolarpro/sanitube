# ADR-0009 — asset_links carries secondary attachments only

- **Status:** Accepted
- **Date:** 2026-08-14
- **Ticket:** ARCH-002

## Context

Assets attach to catalogue entities in two quite different ways, and lumping
them together is what makes asset tables hard to reason about.

Some attachments are **single-valued and load-bearing**: the master of a
recording, the cover of a release. Exactly one, and the answer has to be
unambiguous — ADR-0008 puts those in foreign keys.

The rest are **multi-valued and open-ended**: previews, stems, lyric files,
contracts, distribution exports, alternate artwork. Their number is not known
in advance, new kinds will appear, and no single one is load-bearing.

## Decision

`asset_links` models the second category and only that:

```
asset_id + linkable_type + linkable_id + role + position
unique (asset_id, linkable_type, linkable_id, role)
```

`AssetLinkRole` covers `PREVIEW`, `STEM`, `LYRICS`, `CONTRACT`, `EXPORT`,
`ALTERNATE_ARTWORK`. Adding a role is an enum case and no migration, because
the column is a VARCHAR.

A track's asset *lineage* is a separate concern and lives on the asset itself
(`parent_asset_id`), not here: "this preview was cut from that master" is a
fact about two files, independent of what either is attached to.

## Consequences

**Accepted:**

- Every row in `asset_links` is genuinely optional, so nothing breaks when the
  table is empty.
- New attachment kinds cost an enum case.
- The uniqueness rule is meaningful: the same asset cannot hold the same role
  on the same entity twice, while different roles are free.

**Costs:**

- "All assets for this release" is two queries — the cover foreign key and the
  links — rather than one. Worth it for the ambiguity it removes.
- Polymorphic links have no referential integrity to their target. The
  alternative, a link table per entity type, trades that for a migration every
  time a new entity gains attachments.

## Revisit when

A polymorphic target ends up with orphaned links often enough to matter, which
would argue for a cleanup job or per-type tables. Not before there is evidence.
