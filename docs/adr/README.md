# Architecture Decision Records

An ADR records a decision that was expensive to reach and would be expensive
to reverse: what was decided, why, what it costs, and what would justify
revisiting it.

Deferred decisions belong here too. A choice to *not* decide yet — with the
condition that will unblock it written down — is a decision, and it must not
live only in a docblock where it is invisible to anyone reading the roadmap.

## Format

Each record is `ADR-NNNN-short-title.md` and carries:

- **Status** — Accepted, Superseded by ADR-NNNN, or Deferred (with the ticket
  that resolves it)
- **Context** — the forces in play, including the ones pulling the other way
- **Decision** — what was chosen
- **Consequences** — what this costs, not only what it buys
- **Revisit when** — the concrete trigger for reopening

Records are immutable once accepted. A decision that changes gets a new ADR
that supersedes the old one; the original stays, so the reasoning that was
true at the time remains readable.

## Index

| ADR | Title | Status |
|---|---|---|
| [0001](ADR-0001-modular-monolith.md) | Modular monolith over microservices | Accepted |
| [0002](ADR-0002-portability-baseline.md) | Portability baseline: cPanel is a first-class target | Accepted |
| [0003](ADR-0003-provider-boundaries.md) | Every external service sits behind a SaniTube-owned contract | Accepted |
| [0004](ADR-0004-distributor-write-contract-deferred.md) | Distributor write-side contract deferred to DIST-001 | Deferred |
| [0005](ADR-0005-music-generation-advanced-contract-deferred.md) | Generation extend/remix/stems deferred to GEN-001 | Deferred |
| [0006](ADR-0006-bigint-plus-uuid-v7.md) | BIGINT storage key plus a UUID v7 public identity | Accepted |
| [0007](ADR-0007-artist-relations-canonical.md) | Artist credits are canonical relations, never columns | Accepted |
| [0008](ADR-0008-canonical-master-and-cover.md) | A track's master and a release's cover are foreign keys | Accepted |
| [0009](ADR-0009-asset-link-semantics.md) | asset_links carries secondary attachments only | Accepted |
| [0010](ADR-0010-external-identifier-lifecycle.md) | External identifiers are immutable, revocable, never deleted | Accepted |
| [0011](ADR-0011-read-projections-deferred.md) | Read projections deferred; canonical relations first | Deferred |
