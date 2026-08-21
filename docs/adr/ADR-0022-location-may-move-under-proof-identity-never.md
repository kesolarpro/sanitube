# ADR-0022 — Location may move under proof; identity never

## Status

Accepted (2026-08-21).

## Context

Invariant I1 froze five fields on a stored asset — `disk`, `path`, `sha256`,
`byte_size`, `kind` — on the reasoning that editing any of them would make
the row stop describing the bytes it claims. That reasoning is airtight for
four of the five. It over-reaches on `disk`.

The deployment mission requires migrating an existing catalogue — thousands
of local files — to object storage (R2), resumably and verifiably. A
migration that cannot switch an asset's canonical provider is not a
migration; a migration that switches it by weakening I1 wholesale would
un-freeze exactly the fields whose freezing is the platform's integrity
story.

## Decision

Split the frozen five by what they are. `sha256`, `byte_size`, `kind` and
`path` are **identity** — what the bytes are and what they are called. They
stay frozen forever, with no mechanism to change them. `disk` is
**location** — where a copy of those bytes currently lives — and it may
change under exactly one path:

1. the bytes are stream-copied to the target provider under the same key;
2. the **target** copy is read back and its checksum and size compared
   against what the asset has always claimed — never against a fresh hash
   of the source, which would bless already-corrupt bytes with a matching
   pair of wrong checksums;
3. only then is the save sanctioned, instance-bound and consumed by the
   observer on the one save it blesses; a save that touches `disk` and
   anything else frozen is refused even when sanctioned, because that is
   not a relocation, it is a rewrite wearing one.

Sources are never deleted by the relocation. Retiring local copies after a
verified migration is a separate, explicit, human decision, and the service
deliberately has no delete call to make against a source.

## Consequences

- `sanitube:assets:relocate` exists and is the production caller: batched,
  resumable (done is detected — the asset names the target disk — not
  remembered), observable per file, and a failed copy removes its own
  unverified target object and strands nothing else.
- The unsanctioned write path is exactly as frozen as before this decision:
  every pre-existing I1 test passes unchanged, and a new test proves a bare
  `disk` change still throws.
- The row's promise is restated, not weakened: *these fields describe these
  bytes* — and the bytes provably exist at the named location before the
  name changes.
