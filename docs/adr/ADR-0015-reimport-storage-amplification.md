# ADR-0015 — Re-import storage amplification

**Status:** proposed — **REVIEW_REQUIRED**
**Date:** 2026-08-16
**Ticket:** BULK-001c

## What was found

BULK-001c assumed the platform might identify imported content by its source
object key. **It does not, and never has.** Identity is a SHA-256 of the bytes
that actually landed, computed by reading the staged object back rather than
trusting anything the source said about itself.

`ReimportContentIdentityTest` pins all four cases the ticket names, through the
real pipeline, and they all pass today:

| | source key | bytes | behaviour |
|---|---|---|---|
| **A** | same | same | recognised as already held; `duplicate_of_asset_id` set both ways |
| **B** | same | **changed** | ingested as new content — the replaced master is *not* skipped |
| **C** | different | same | recognised as already held; both filenames kept |
| **D** | different | different | two ordinary imports |

Case **B** is the one that makes "same key means unchanged" forbidden, and it is
the one already safe: an operator who re-exports a master and uploads it over
the old one gets the new take imported, not silently dropped.

**So the forbidden shortcut is not present and does not need removing.** What
remains is narrower than the ticket assumed, and it is real:

> **Amplification factor: 2.** Identical content occupies exactly twice its own
> size. `AssetStorageService::store()` finds the duplicate, records the
> relationship — and then moves the staged object into the second asset's
> canonical key anyway.

That is asserted, with the number, in
`identical_bytes_are_currently_stored_twice_and_this_is_the_baseline`.

## Why this is an amendment and not a fix

Removing the second copy means an asset that has no object of its own. That
contradicts two things that are currently load-bearing:

- **AST-001's invariant**: an asset's object key is derived from its own UUID,
  which is what makes a retry overwrite a partial write instead of orphaning
  one. Sharing a key between two assets breaks the one-to-one that invariant
  rests on.
- **A merged assertion**: `AssetStorageWorkflowTest` states it outright —
  *"Provenance is preserved on both sides: two assets, two objects."* Today's
  behaviour is not an oversight; it was chosen and written down.

Deleting one asset would then delete another's bytes. Verification would read
back through a path the asset does not own. `AssetStatus` has no case meaning
"holds no bytes of its own". None of that is a refactor.

## The options, with what each costs

**1. Shared canonical object.** On a duplicate, discard staging and point the
second asset's `path` at the first's object.
*Cost:* an asset's path is no longer derived from its UUID; deletion needs
reference counting; `AssetStatus` needs a case; ADR-0008/0009 need revisiting.
*Benefit:* amplification 2 → 1, and provenance is unchanged because
`duplicate_of_asset_id` already carries it.

**2. No second Asset row.** On a duplicate, the ingestion item adopts the
existing asset instead of keeping its own.
*Cost:* loses the per-import asset row, and with it the record that a second
import happened at all — `TrackCandidate.matched_asset_id` would have to carry
the whole story.
*Benefit:* every invariant stays intact; nothing in the storage core changes.

**3. Hash before upload.** Spool the source locally, hash it, upload only if
new.
*Cost:* **not viable.** `SANITUBE_MAX_MASTER_BYTES` defaults to 2 GB, and this
platform's baseline is a shared cPanel account. Spooling a 2 GB master to local
disk to avoid storing it remotely trades the right resource for the wrong one.
Reading the source twice instead would double egress on every import.

**Recommendation: option 2**, on the grounds that it achieves the ticket's goal
without touching an invariant that three other tickets depend on. Option 1 is
the better long-term model and belongs to a storage-lifecycle ticket that can
afford reference counting.

## What is not in question

- Identity stays cryptographic. No ETag, no version header, no key comparison.
  §3's "only trust those where semantics are known" resolves to trusting none of
  them: an S3 ETag is a content MD5 only for single-part uploads, and a master
  is exactly the kind of object that arrives multipart.
- Staging stays transient. `nothing_is_left_in_staging_after_a_run` guards it,
  because a permanent staged copy would be a worse amplification than the one
  being measured.
- Provenance is never traded away for storage. Whatever an amendment does, the
  platform must still be able to say that a second import happened and what it
  matched.

## Consequences

- Measured and pinned. The baseline test fails when the amendment lands, which
  is how the change announces itself.
- Held for review, per BULK-001c's own instruction that an implementation
  requiring a domain architecture amendment is `REVIEW_REQUIRED`.
- The amplification is **bounded and visible**, not a leak: it costs one extra
  copy per duplicated master, only when the same content is imported twice, and
  the operator can see it because both assets are listed with the relationship
  recorded.
