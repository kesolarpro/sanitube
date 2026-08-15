# ADR-0012 — No distributed transaction between the database and object storage

**Status:** accepted
**Date:** 2026-08-15
**Supersedes:** nothing
**Related:** ADR-0003 (provider boundaries), ADR-0006 (BIGINT + UUID v7)

## Context

An asset is two things that must agree: a row in MySQL saying bytes exist, and
an object in storage that is those bytes. Every write touches both, and there is
no mechanism that can commit them together. Object storage has no notion of a
transaction that MySQL could enlist in, and a two-phase commit across them would
need a coordinator the deployment baseline forbids — no Redis, no Supervisor, no
daemon on shared hosting.

So the two can disagree. The only question is what happens when they do.

The tempting answer is to write the row first and the object second inside a
database transaction, and let the rollback "undo" the upload. It does not. The
object is already there and the rollback knows nothing about it. That design
does not remove the inconsistency; it hides it, and it hides it in the direction
that loses track of files.

## Decision

**Do not claim atomicity. Make every disagreement survivable, and say which one
each failure produces.**

Four properties carry the whole design:

1. **Bytes land in staging first.** `staging/{uuid}/original.ext` is a reserved
   prefix. A failed upload therefore never produces an object at the address the
   catalogue will later call canonical.

2. **The canonical key is deterministic.** It is derived from the asset's UUID
   and nothing else. There is exactly one address for an asset's bytes, ever, so
   a retry addresses the same object as the attempt that failed. Retries
   converge instead of accumulating — this is the property that makes everything
   else safe.

3. **The database is only updated after the object is canonical.** If that
   update fails, the asset stays `PENDING` and the object sits at its key. The
   state is wrong, but it is wrong in a way the next attempt corrects, and it is
   never wrong in the direction of a row claiming bytes that are not there.

4. **Verification reads storage.** `VERIFIED` is written only after the object
   has been read back and hashed. No other code path may set it.

### Deleting from staging

The sweep that reclaims abandoned uploads is the one scheduled task whose bug
would read "deleted a master", so it removes an object only when five
conditions hold at once: the provider returned it inside the exact staging
scope; the key parses as one this platform could have written
(`staging/{uuid}/original[.ext]`, exactly); no asset claims that path; it is
older than the threshold; and this is not a dry run.

The age threshold has a floor of one hour that no configuration can lower —
`SANITUBE_STAGING_TTL_HOURS=0` is a typo, and without a clamp it means "delete
every upload currently in flight". Only an explicit, warned CLI option goes
below it, and the scheduler passes no options at all.

A physically separate store for staging would be a stronger boundary and is
deliberately not required: it would have to be configured correctly on every
provider, on shared hosting and in the installer, and a boundary that is easy
to misconfigure on five surfaces is not obviously safer than one the code
enforces on all of them.

### The resulting failure table

| Failure | State | Resolution |
| --- | --- | --- |
| Upload dies part-way | `PENDING`; partial object in staging | Staging sweep |
| Checksum/size rejected | `PENDING`; staging object deleted at once | Retry |
| Promotion succeeded, database write failed | `PENDING`; object at canonical key | Retry overwrites the same key |
| Same upload runs twice | Unchanged | Second call returns the asset |
| Retry with different bytes | Refused | Stored bytes are never replaced |
| Object deleted underneath us | `MISSING` on next verification | Restore |
| Object changed underneath us | `QUARANTINED` on next verification | Investigate |
| Provider unreachable | Status **unchanged** | Retry later |

The last row is a decision, not an omission. An unreachable provider is not
evidence about an asset. Recording `MISSING` because a network call timed out
would turn a blip into a catalogue-wide incident, and re-verifying afterwards
would not undo what read the status in between.

## Consequences

**Accepted:**

- A crash can leave a `PENDING` asset whose bytes are already canonical. It is
  visible, it is queryable, and the next upload attempt fixes it.
- The staging sweep is required infrastructure, not a nicety. Without it,
  abandoned uploads accumulate until a quota stops something that matters.
- Every upload costs one extra read of the staging object, to hash what actually
  landed. That is the price of the checksum meaning something.

**Rejected:**

- *Row first, object second, inside a transaction.* Hides the inconsistency
  rather than removing it.
- *Object first, row second, with a compensating delete on failure.* The
  compensating delete can fail too, and now the failure path has a failure path.
  Leaving the object at a deterministic key and letting the retry overwrite it
  is strictly simpler.
- *An outbox table.* Correct, and more machinery than the problem needs while
  the only writer is the application itself. Reconsider when uploads arrive
  directly from clients.

## Architecture amendment — assets.sha256, byte_size and mime_type become nullable

Recorded here because it changes a decision ARCH-002 had already made, and a
ticket may discover that an accepted model is insufficient but may not treat
its own correction as approved.

**Original decision.** ARCH-002 declared `sha256`, `byte_size` and `mime_type`
`NOT NULL` on `assets`. That was right for the only state it modelled: a row
describing bytes that exist.

**Problem discovered.** The upload workflow adds the state before that one. An
asset is registered, and only then do the bytes arrive; in between, all three
are genuinely unknown. `NOT NULL` leaves two options, and one of them is a lie
— a placeholder checksum on every pending row. A column whose value is
sometimes a hash and sometimes a placeholder cannot be checked at all, and a
fabricated `sha256` is precisely the value that makes a later verification
quarantine an innocent master.

**Proposed change.** Make the three columns nullable, and add invariant **I10**
requiring all three from `STORED` onwards, enforced in `AssetObserver` where
every write path meets it. The guarantee moves from the column definition to
the invariant and gets stronger on the way: `NOT NULL` never prevented a row
reaching `VERIFIED` with a meaningless checksum, and I10 does.

**Compatibility and data migration impact.** Additive and non-destructive.
`ALTER TABLE` on three columns, no data rewritten, no rows touched, no index
changed. Existing rows already satisfy I10 because they were written under
`NOT NULL`. Verified `migrate → suite → migrate:rollback → migrate` on SQLite,
MySQL 8.0, MariaDB 10.6 and MariaDB 11.4.

**Rollback impact.** `down()` restores `NOT NULL` and therefore succeeds only
while no asset is still unmeasured. That is deliberate, and stated in the
migration: restoring the constraint with a `PENDING` asset present would mean
inventing a checksum for bytes nobody has hashed. An installation that needs to
roll back must first resolve or remove its pending uploads — a decision an
operator should make, not a migration.

**Tests.** An asset cannot reach `STORED` without all three (I10); an asset
cannot be forced to `VERIFIED` with no bytes anywhere; a registered asset has
a null checksum until bytes arrive; the full `PENDING → STORED → VERIFIED`
path; and the migration's reversibility, exercised on all four engines by CI.

## Physical deduplication is out of scope

Identical bytes are detected by SHA-256 and recorded through
`duplicate_of_asset_id`, but **both objects are kept**.

Pointing two assets at one object makes deletion a reference-counting problem
spanning releases, distributions and derivatives. Getting that count wrong once
means deleting a master that something still references. Storage is cheaper than
that, and the duplicate relationship is recorded either way, so a future
content-addressable layer can be built on data that already exists.
