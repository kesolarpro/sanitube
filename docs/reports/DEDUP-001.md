# DEDUP-001 — telling two copies apart from two recordings

## Where this sits

MED-003 gave every asset a fingerprint and a way to narrow fifty thousand rows
to a handful worth comparing. It stopped deliberately short of comparing them.
This ticket does the comparison, decides what the result means, and writes the
finding down where a person can answer it.

It does **not** delete, merge, or resolve anything. That is the next ticket, and
keeping it separate is the point: the code that finds duplicates and the code
that acts on them should not be the same code.

## The format change, and why it happened immediately

MED-003 stored what `fpcalc` prints by default — the base64 compressed
fingerprint. **That form cannot be compared.** It is entropy-coded: two
fingerprints differing by one bit compress to strings differing everywhere. It
supports equality and nothing else.

Equality was never the interesting question. A WAV and an MP3 of one master are
the same recording and are never byte-identical; if equality were enough, the
SHA-256 the platform already computes would have answered it three tickets ago.

So `-raw` is now passed, the 32-bit words are stored, and `VERSION` goes to
`2` — which is what that constant is for. Fingerprints from version 1 are not
compared against version 2 ones; the candidate search filters on algorithm, so
they are simply never each other's candidates.

**What this gives up:** the compressed form is what AcoustID's API accepts.
Nothing submits to AcoustID today and §3 keeps external providers out of the
domain, so the trade is one-sided for now. If a lookup ticket ever arrives it
adds a second column beside this one — it is deferred, not blocked.

## The measurement

Similarity is the fraction of bits that agree once the two fingerprints are
lined up. Every frame contributes 32 independent observations.

**The alignment search is the part that decides whether this works at all.** The
same recording encoded twice routinely starts a fraction of a second apart — an
encoder pads the front, a rip begins a frame early, a master is exported with
the leading silence trimmed. Compared head-to-head, those two fingerprints
agree on about half their bits, which is exactly what two *unrelated* files
score. Without sliding one against the other, the platform would report that a
file is not a duplicate of itself re-encoded, which is the single most common
thing it will ever be asked.

`the_same_audio_starting_a_moment_later_still_matches` pins it, and removing the
search fails it.

Two numbers worth keeping in mind, both asserted:

- **Identical: 1.0.** A single flipped bit still scores above 0.999, which is
  why this is a comparison and not a hash.
- **Unrelated: ~0.5**, not 0. Independent bits agree half the time. A threshold
  chosen as though unrelated meant zero — 0.5, say — would propose that every
  pair of files in the catalogue is related. The defaults sit at 0.90 and 0.75.

Not-comparable is a distinct answer from a similarity of zero. Zero is
measured-and-different; not-comparable means no measurement happened, and
collapsing them would let a missing fingerprint read as positive evidence of
difference.

## What the platform may conclude

ADR-0016, written with this ticket. Three levels, named rather than scored,
because the difference is categorical:

| Level | Evidence | Kind of claim |
|---|---|---|
| `EXACT_DUPLICATE` | identical SHA-256 | **measured** |
| `SAME_RECORDING` | acoustic agreement ≥ 0.90 | **proposed** |
| `POSSIBLE_RELATION` | acoustic agreement ≥ 0.75 | **proposed** |

Every finding is written `PROPOSED`, **including the exact ones**. Byte identity
is a fact; what to do about it is not. A platform that auto-resolves the certain
half teaches its operators that the queue only holds hard cases, which is
exactly when a legitimate second copy gets discarded without anyone reading the
screen.

Cover and remix detection is deliberately absent — see ADR-0016 for why a lower
threshold is the wrong tool for it.

## Where the decisions live

The comparator returns a number and the evidence behind it. The thresholds that
turn a number into a level are configuration. That split is not tidiness: it
means **recalibrating re-reads stored fingerprints rather than stored masters**.
Nobody re-reads fifty thousand masters to change one number, so a threshold
baked into the measuring code is a threshold that never gets changed.

It is also the plane boundary. A worker measures — the same number whoever
asked. Whether 91% means "the same recording" is a judgement about this
catalogue, and putting it in the worker would mean two installations with
different tolerances need two different workers.

## Safety

- **Nothing is deleted or marked redundant**, asserted directly.
- **A decision is never reopened or overwritten**, and neither is the evidence
  under it — a verdict beside numbers nobody decided on cannot be explained.
- **Re-running does not grow the queue.** A nightly pass re-evaluates
  everything; if that multiplied findings the review screen would be unusable
  within a week.
- **A pair seen from the other side is not queued twice.** Direction is kept
  because a reviewer needs to know which copy arrived second, but the pair is
  one thing to answer.
- **No fingerprint is not evidence of difference.** Chromaprint is absent on
  most shared hosting; the honest answer there is that the files have not been
  compared.
- Turning acoustic evaluation off leaves checksum findings running, because
  byte identity is observed rather than inferred and costs nothing.

## Mutation testing

Every claim above was inverted and the named test failed, one failure each:

| Mutation | Test that failed |
|---|---|
| reverse-pair guard removed | `the_same_pair_seen_from_the_other_side_is_not_queued_twice` |
| decided findings overwritable | `a_finding_somebody_has_answered_is_left_alone` |
| checksum route disabled with the acoustic one | `switching_acoustic_evaluation_off_keeps_the_checksum_findings` |
| alignment search reduced to offset 0 | `the_same_audio_starting_a_moment_later_still_matches` |
| `-raw` dropped from the invocation | `the_raw_flag_is_passed_and_the_filename_stays_a_separate_argument` |

## A finding — the real fingerprinter had no test

Every test in this feature runs against the fake, which emits well-formed points
by construction. Nothing exercised the code that reads actual `fpcalc` output —
and this ticket rewrote it.

Had the parser been wrong, every test would have stayed green while every
fingerprint in a production catalogue was nonsense. `ChromaprintFingerprinterTest`
now pins it against captured output, including the case that matters most: a
compressed fingerprint arriving where raw words were promised is **refused**
rather than stored, because storing it would not fail — it would make every
later comparison quietly meaningless.

## A second finding — `Royalties` was a registered module

`config/sanitube.php` listed `Royalties` among the modules. No code exists
behind it, and the registry only wires up what is on disk, so it was inert. It
is removed: the platform's scope excludes royalties, revenue, payouts, balances
and reconciliation entirely, and a registered module name is a standing
invitation to build one.

## A third finding — CI caught a second engine trap in the same PR

The first push of this ticket failed MariaDB 10.6 alone, before a single test
ran:

```
SQLSTATE[42000]: 1067 Invalid default value for 'detected_at'
```

MySQL and MariaDB give the **first** `TIMESTAMP` column in a table an implicit
`DEFAULT CURRENT_TIMESTAMP` and every later `NOT NULL` one an implicit zero-date
default, which strict mode rejects. `decided_at` is nullable and declared first,
so `detected_at` inherited the broken default purely by being second.

The column now states its default. A repo-wide scan found no other migration
relying on the implicit one, so the rule is cheap to keep. ADR-0017 records both
this and the unsigned-arithmetic trap together, because the lesson is the same
one twice: **neither was reachable from a local SQLite run, and both would have
reached production on the one engine the platform actually deploys to.**

## Not in this ticket

- **The review screen.** Findings are recorded; answering them is DEDUP-002.
- **Trash.** Nothing moves or is deleted; logical trash is its own ticket, and
  no similarity score will ever trigger it.
- **Backfilling the catalogue.** Evaluation runs per asset on demand. Scheduling
  it over an existing library needs the backlog circuit breaker.
- **Cover and remix detection.** See ADR-0016.

## Gate

1278 tests · 5987 assertions · 1 skipped · PHPStan 6 clean, no baseline · Pint
clean.
