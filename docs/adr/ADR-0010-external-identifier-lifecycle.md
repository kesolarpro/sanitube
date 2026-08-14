# ADR-0010 — External identifiers are immutable, revocable, and never deleted

- **Status:** Accepted
- **Date:** 2026-08-14
- **Ticket:** ARCH-002

## Context

An ISRC, a UPC or a distributor's release id is not application data — it is a
name the outside world already uses. Once a release is delivered, that value
exists in store metadata, royalty statements and reports SaniTube does not
control and cannot edit.

Three consequences follow, and none of them are obvious from inside the
application:

1. **Editing an identifier changes nothing outside.** It only destroys the
   record of what was true, leaving statements that reference a value the
   catalogue no longer contains.
2. **Deleting one is worse.** The identifier is still in circulation; the
   catalogue simply loses the ability to explain it.
3. **A second active identifier of the same kind is the expensive failure.**
   For a recording that already carries an ISRC, minting another does not
   produce a duplicate row — it splits that recording's earnings between two
   identifiers, and the damage surfaces months later in a report that no longer
   reconciles.

The third is not hypothetical: a large part of the initial catalogue was
distributed elsewhere before SaniTube existed.

An additional wrinkle: the same *type* of identifier can legitimately exist
several times when different counterparties issue it. Two services each have
their own id for one recording, and neither may block the other.

## Decision

**Immutable identity.** After creation, none of `identifiable_type`,
`identifiable_id`, `type`, `namespace`, `value`, `is_authoritative`, `source`
or `assigned_at` may change. Enforced in an observer, so no future code path —
import, admin screen, console command — can bypass it.

**Deletion refused** at the application layer, outright.

**Revocation, not mutation.** The only permitted change is `active_marker`
going 1 → NULL, through `RevokeExternalIdentifier`, which in one transaction
writes a row in `external_identifier_revocations` (unique per identifier, so a
second revocation is impossible) and clears the marker. NULL → 1 is refused:
resurrecting an identifier the world has been told is gone is never correct.

**Uniqueness enforced by the engine, not by the service.** Two unique indexes:

| Index | Guarantees |
|---|---|
| `(type, namespace, value)` | one identifier value means one thing |
| `(identifiable_type, identifiable_id, type, namespace, active_marker)` | at most one active identifier of a kind per entity |

The second works because every engine in the matrix treats `NULL`s as distinct
inside a unique index: any number of revoked rows coexist, only one active row
can. No partial index — PostgreSQL-only. No `CHECK` — inconsistent across the
matrix. No trigger.

`namespace` is part of both rules, which is what allows the same type under two
counterparties while still blocking a duplicate under one.

## Consequences

**Accepted:**

- The catalogue can always explain any identifier that ever left it.
- The guarantee holds against code that never heard of the service. A test
  writes directly to the model, bypassing `AssignExternalIdentifier`, and the
  database still refuses.
- Legacy imports are safe by construction rather than by discipline.

**Costs:**

- Fixing a typo means revoke-then-reassign, two rows where a UPDATE would have
  done. That is the point.
- `external_identifiers` only grows.
- The `active_marker` column exists solely to carry a constraint, and reads
  oddly until the index is understood — hence this ADR and the comment in the
  migration.
- Assignment is not idempotent by accident: re-assigning the *same* value
  returns the existing row so a re-run import does not fail, while a
  *different* value is refused.

## Revisit when

- An engine in the support matrix changes its NULL-in-unique-index semantics —
  it would break the guarantee silently, which is why the behaviour is asserted
  by a test on all four engines rather than assumed.
- An audit-facing API needs revoked identifiers; that is an additive endpoint,
  not a change to this model.
