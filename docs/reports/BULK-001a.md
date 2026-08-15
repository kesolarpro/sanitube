# BULK-001a — Batch progress, and what BULK-001 actually still needs

## The finding that matters most

**BULK-001 is much smaller than the ticket assumes, because ING-001 already
built most of it.** Before writing anything I read what exists, and the
substrate for importing nine hundred files is already there:

| BULK-001 requirement | Already provided by ING-001 |
| --- | --- |
| batch + per-item state | `ingestion_batches`, `ingestion_items` |
| idempotent retry, no silent duplicates | `UNIQUE(ingestion_key, active_marker)` |
| retry accounting | `ingestion_items.attempt_count` |
| per-file failure isolation | `failure_code`, `failure_message` per item |
| partial failure as a first-class outcome | `IngestionBatchStatus::CompletedWithErrors` |
| browser need not stay open | `ProcessIngestionItemJob`, `FinalizeIngestionBatchJob` |
| many files from one instruction | `StartIngestionBatch::handle()` takes a **prefix** or explicit references |
| cloud source | `CloudImportReader`, `SourceReaderFactory` |

**Do not create a `BulkImportBatch` table.** A parallel path would duplicate the
idempotency guarantee, and two mechanisms that both claim to prevent duplicate
imports is worse than one — the second is the one nobody tests against a real
retry.

What genuinely remains is listed under "still outstanding" below.

## What this PR adds

`IngestionBatchProgressQuery` — how far a batch has got, **derived from the
items rather than stored beside them.**

A batch of nine hundred files could carry `completed` / `failed` / `duplicate`
columns updated as each item finishes. That is faster to read and wrong the
first time a worker dies mid-item, a retry runs twice, or somebody intervenes by
hand. A progress screen that disagrees with the item list is worse than no
progress screen: it is one an operator learns to stop believing.

The cost of deriving it is **one grouped count per batch**, asserted by test. If
that ever becomes the bottleneck, the answer is a projection rebuildable from
the items — not a counter that is only ever incremented.

Every item state is expanded against the enum, so a state with no rows reads as
a real `0`. "No failures" and "the failed bucket fell out of the result set"
call for opposite reactions from somebody watching an import of nine hundred
files.

**Two figures are deliberately absent: a percentage and an estimated
completion time.** Both would be invented. Item durations vary by orders of
magnitude between a three-minute MP3 and an hour-long WAV, and a progress bar
that stalls at 94% teaches people to ignore progress bars.

## Guardrails proven

Two regressions injected; **three tests went red** before either was trusted:

| Injected regression | Caught by |
| --- | --- |
| enum expansion removed — empty buckets vanish | counts every state including the empty ones; progress follows the items |
| batch scoping removed — every batch counts every item | one batch never counts another's items; progress follows the items |

## Tests

6 new tests. **724 PHP tests / 2961 assertions**, 1 skipped (the real ffprobe
run), plus 16 component tests.

## Still outstanding for BULK-001

1. **Manifest import (CSV).** Metadata from a manifest is *import evidence*, not
   canonical data. Where it conflicts with what the file itself says, or with an
   existing catalogue record, it must be surfaced for review rather than
   applied. Existing ISRC/UPC are preserved and never regenerated; provenance is
   `LEGACY_IMPORT`.
2. **A way to start a batch from the interface** — the service already accepts a
   prefix; nothing exposes it yet.
3. **UI-004**, which is where this progress read model gets rendered.

## Architecture amendment

**None.** No migration, no new table, no invariant touched. One read model that
only reads.
