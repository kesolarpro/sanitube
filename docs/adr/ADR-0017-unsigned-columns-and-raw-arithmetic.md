# ADR-0017 — Unsigned columns and raw arithmetic

**Status:** accepted
**Date:** 2026-08-20
**Ticket:** MED-003 / DEDUP-001
**Extends:** ADR-0002 (portability baseline)

## Context

MED-003 shipped a candidate query that ordered results by their distance from a
target duration, in the obvious way:

```sql
ORDER BY ABS(duration_seconds - 300)
```

It passed locally and on every SQLite job. It failed MySQL 8.0, MariaDB 10.6
and MariaDB 11.4 identically:

```
SQLSTATE[22003]: Numeric value out of range: 1690
BIGINT UNSIGNED value is out of range in '(`audio_fingerprints`.`duration_seconds` - ?)'
```

`duration_seconds` was declared `unsignedInteger`. **MySQL and MariaDB evaluate
unsigned minus unsigned as unsigned**, so the first candidate shorter than the
target underflows and the engine aborts the entire statement — `ABS` never
runs, because there is no negative number for it to receive. SQLite has no
unsigned arithmetic at all and quietly returns a negative integer, so the same
expression is correct there and fatal everywhere else.

Two things about how this was found are worth recording:

- **The type declaration was doing something at runtime.** `unsignedInteger`
  reads like documentation — "this value is never negative" — and on SQLite it
  effectively is. On the rest of the matrix it changes the semantics of every
  arithmetic expression the column appears in.
- **One test caught it, and coverage was the reason it was only one.** Every
  other candidate query in the suite happened to compare against rows at or
  above the target. The defect was not rare; the fixtures were one-sided.

## Decision

Three rules, all narrow enough to follow without thinking about them.

**1. Raw SQL never subtracts an unsigned column.** Where a distance is needed,
subtract the smaller value from the larger explicitly:

```sql
ORDER BY CASE WHEN duration_seconds >= ? THEN duration_seconds - ?
              ELSE ? - duration_seconds END
```

Both branches are non-negative on every engine, and it needs no cast — `CAST(x
AS SIGNED)` is MySQL syntax that SQLite accepts only by an affinity rule that
happens to work, which is not a portability guarantee.

**2. A column is only declared unsigned when the range is the point.** Foreign
keys stay unsigned, because they mirror `id` and are never arithmetic operands.
For a measurement, prefer a signed `integer()`: it costs nothing, and the
non-negativity was documentation the application was already enforcing.
`duplicate_relations.similarity_basis_points` and `overlap_frames` are signed
for exactly this reason, though neither is ever negative.

**3. A comparison ordering has a deterministic tiebreak.** Two rows equidistant
from a target are otherwise returned in whatever order the engine chooses, and
when the list is capped that decides which row survives. Truncation must not be
a coin toss.

## Consequences

**Accepted:**

- Distance ordering is portable and needs no per-engine branch.
- The failure mode is now written down, so the next person reaching for
  `ABS(col - ?)` finds out here rather than from CI.

**Costs:**

- The CASE form is wordier than `ABS`, and looks like over-engineering to
  anyone who has only run the suite on SQLite. The comment at the call site
  carries the reason.
- Signed columns for values that cannot be negative reads as a missing
  constraint. It is a deliberate trade: the constraint was never enforced by
  the column anyway, and its only observable effect was to break three engines.

**Not addressed:**

No scanner enforces this. A textual guard cannot tell which columns in a raw
expression are unsigned, and a rule that fires on every `whereRaw` containing a
minus sign would be turned off within a week. The CI matrix is the enforcement
— which is what caught it, one commit after it was written.

## Revisit when

- The support matrix drops MySQL and MariaDB, which is the only change that
  makes unsigned arithmetic uniform again.
- A static analyser gains column-type awareness for raw SQL, at which point
  rule 1 becomes checkable rather than conventional.
