# ADR-0016 — Measured, proposed, suggested

**Status:** accepted
**Date:** 2026-08-20
**Ticket:** DEDUP-001

## Context

The platform is about to start producing statements it did not observe. A
fingerprint comparison says two masters are 91% alike; a threshold turns that
into "the same recording"; later tickets add transcription and language models
that will say a track is in French, or that its title should be capitalised
differently.

All of those arrive through the same code paths and land in the same columns as
facts the platform actually measured — a SHA-256 read back from storage, a
duration reported by ffprobe. Once they are stored side by side they become
indistinguishable, and an interface cannot show a reviewer which is which
because the distinction was never recorded.

That is how a catalogue quietly fills with confident guesses. The specific
failure to avoid: an operator sees "same recording" beside a master, believes
the platform checked, and discards a take that was never compared to anything.

## Decision

Every derived statement carries **which kind of claim it is**. Three levels,
and they are named rather than scored, because the difference is categorical
and no threshold converts one into another.

| Level | Means | May the platform act alone? |
|---|---|---|
| **MEASURED** | Observed from the bytes or the file. A checksum, a byte count, a duration read by a tool. | Yes — it is a fact. |
| **PROPOSED** | Derived by platform logic from measurements, across a threshold the installation configures. | No. Shown as a proposal, with its evidence, for a person to accept. |
| **SUGGESTED** | Produced by an external model. A transcript, a language guess, a title. | No. Never written into the catalogue without a person. |

Three rules follow, and they are the whole point:

1. **A proposal never becomes catalogue truth on its own.** Not at 99%, not at
   a hundred consecutive correct decisions.
2. **The evidence is stored with the claim**, not just the conclusion — the
   measurement, what it was taken over, and which algorithm version produced
   it. A conclusion whose evidence was discarded cannot be re-decided when a
   threshold changes; it can only be recomputed from the masters, and nobody
   re-reads fifty thousand masters to change one number, so the number never
   gets changed.
3. **Thresholds live in configuration, never in the measuring code.** The
   comparator returns a number; policy turns it into a level. Recalibrating
   re-reads stored fingerprints instead of stored audio.

### What this means for deduplication

`DuplicateLevel` has exactly three cases, and they map onto the levels above:

- `EXACT_DUPLICATE` — identical SHA-256. **MEASURED.** A collision is not
  something that happens by accident, and no threshold is involved.
- `SAME_RECORDING` — acoustic agreement above the configured threshold.
  **PROPOSED.**
- `POSSIBLE_RELATION` — agreement above a lower threshold. **PROPOSED**, and
  deliberately worded as "worth a look" rather than as a relationship.

Every finding is written with `PROPOSED` against it, *including the exact
ones*. Byte identity is a fact; what to do about it is not. A platform that
auto-resolves the easy half teaches its operators that the queue only contains
hard cases, which is precisely when a legitimate second copy gets discarded
without anyone reading the screen.

### What is deliberately not built

**Cover, remix and alternate-take detection.** It is a research problem, not a
threshold. On a catalogue built largely from one artist's work in one style,
the acoustic distance between a remix and an unrelated track by the same artist
is small, and a detector tuned to find the first will report the second. Two
recordings that share a composition and nothing else are not something an
acoustic fingerprint can identify at all — that needs the composition, which is
editorial data the platform has not collected.

Adding it later needs a different kind of evidence, not a lower threshold.

## Consequences

**Accepted:**

- A reviewer can always tell what the platform observed from what it inferred.
- Thresholds are recalibrated in minutes against stored evidence.
- No automated path exists from a similarity score to a deleted master.

**Costs:**

- Every derived statement needs somewhere to record its level and its evidence,
  which is more columns and more code than storing the conclusion alone.
- Exact duplicates sit in a review queue that a person has to work through,
  even though the platform is certain about them. That is intended, and it will
  be the first thing somebody asks to automate.

## Revisit when

- A reviewer's decision is itself recorded well enough to train on — at which
  point auto-resolving *exact* matches under an explicit, disable-able policy
  becomes arguable. It stays wrong for the proposed levels.
- Editorial composition data exists, which is the missing input that would make
  relation detection a different question rather than a looser one.
