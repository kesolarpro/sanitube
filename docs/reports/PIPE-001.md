# PIPE-001 — connecting what was built

## The finding this ticket exists for

**Nothing in the application ever called any of it.**

MED-003 built fingerprinting. DEDUP-001 built comparison and the three levels.
DEDUP-002 built the decision and the trash. DEDUP-003 built the review queue.
Every one of those shipped green, with mutation-tested assertions, and on a real
installation the duplicate queue would have been **permanently empty**.

A grep for callers outside the classes themselves returned docblocks and tests:

```
EvaluateAssetDuplicates  → 1 hit, a docblock in DecideDuplicate
FingerprintAsset         → 2 hits, both docblocks
TranscribeAsset          → 0 hits
```

Analysis was wired — `TrackCandidateCreated` → `ScheduleCandidateAnalysis` →
`AnalyzeAudioAssetJob`. Fingerprinting and evaluation had no equivalent, and
`AudioFingerprinter` was bound in the container by a provider that never asked
anything to use it.

Four tickets' worth of tests passed because each tested its own service directly.
**None of them tested that anything calls it**, which is a gap in how I was
writing tests, not an oversight in one of them.

## Two listeners, and the overlap is the point

| Event | Listener | Why |
|---|---|---|
| `AssetStored` | `EvaluateWhenStored` | the checksum half, which needs no Chromaprint |
| `AssetFingerprinted` | `EvaluateWhenFingerprinted` | the acoustic half, where it is available |

Most installations have no Chromaprint. If evaluation only ever followed a
fingerprint, they would find **nothing at all** — not even the byte-identical
duplicates, which are the commonest case by far and need no acoustic comparison.
`an_installation_that_cannot_fingerprint_still_finds_the_byte_identical_ones`
pins that.

Evaluating twice is free: `EvaluateAssetDuplicates` is idempotent per pair and
route, which DEDUP-001 already asserted.

## Fingerprinting sits beside analysis, not inside it

FFmpeg and Chromaprint are installed independently. A server with one and not
the other should get the half it can do — folding fingerprinting into
`AnalyzeAudioAssetJob` would make a missing `fpcalc` look like a failed
analysis.

`AssetFingerprinted` is an event rather than a direct call so Media can stay
ignorant of what a fingerprint is for. Deduplication listens; the dependency
points one way; an installation without that module simply has nobody
listening. Nothing is announced when the server cannot fingerprint — an ordinary
state, and nothing new to compare.

## The backfill, which is what OPS-002 was for

`sanitube:duplicates:evaluate` compares a library imported before any of this
existed — on any installation that has been running a while, that is all of it.

- **It asks the gates first**, and names the refusal. A sweep that ignored the
  switch an operator just pressed would be the first thing to make that switch
  untrustworthy.
- **`--dry-run`** reports and writes nothing.
- **`--limit` defaults to 500**, as the honest default rather than an escape
  hatch: a first run on a large library should finish, be looked at, and be run
  again, instead of discovering after forty minutes that the thresholds were
  wrong.
- It prints that nothing was deleted and nothing decided, because the finding
  count is otherwise read as "duplicates dealt with".

Evaluation runs inline rather than enqueuing one job per asset, so the admission
asks for one unit of work and the ceiling is about what is *already* queued.

## A test of mine was defective again, and the mutation caught it

`the_backfill_leaves_the_trash_alone` **passed with the command's trash filter
deleted**. `EvaluateAssetDuplicates` already refuses a trashed asset, so a test
that only counted findings proved the service's guarantee and said nothing about
the command.

Rewritten to assert the count the command *reports* — `Evaluated 1 asset(s)` —
which is the thing the filter uniquely affects. It fails with the filter removed.

That is the second time in this run that an assertion named one thing and tested
another, and both were found by inverting the code rather than by reading it.

## One existing test changed meaning

`a_trashed_asset_stops_producing_findings_in_both_directions` asserted that **no**
relation existed after trashing. That was only true because nothing evaluated
automatically. A finding now exists from the moment the second copy landed — made
while both were live, and the record of *why* that one was trashed.

The two assertions naming the test's actual property still pass untouched. The
third now asserts the count does not **grow**, which is what the test claims.
Withdrawing findings when an asset is trashed would erase the reason it was
trashed, so it is deliberately not done; DEDUP-003's payload carries `is_trashed`
per side so the screen can show it.

## Mutation testing

| Mutation | Test that failed |
|---|---|
| nothing listens for `AssetStored` (the original state) | `storing_an_asset_finds_an_exact_duplicate…` (+1) |
| no fingerprint job is queued (the original state) | `a_candidate_appearing_queues_a_fingerprint_beside_the_analysis` |
| a fingerprint stops announcing itself | `a_fingerprint_announces_itself_so_deduplication_can_compare` |
| the backfill ignores the stop | `the_backfill_respects_the_stop_an_operator_just_pressed` |
| the backfill sweeps the trash | `the_backfill_does_not_even_look_at_the_trash` |

The first two are the bug this ticket fixes, reproduced deliberately.

## Not in this ticket

- **Transcription is still not called anywhere**, and that is now the one
  remaining disconnected service. It is deliberate: with no vendor adapter
  shipped, `TranscribeAsset` would return null on every installation, and wiring
  a call that can only ever do nothing is worse than leaving the seam visible.
  It gets wired with the adapter.
- **A fingerprint backfill.** `sanitube:duplicates:evaluate` compares what has
  been fingerprinted; fingerprinting an old library is a heavier sweep that
  reads every master and belongs in its own ticket with its own limit.
- **Hiding findings whose sides are trashed.** The queue can filter; whether it
  should by default is a review-workflow decision.

## Gate

1339 tests · 6239 assertions · 1 skipped · PHPStan 6 clean, no baseline · Pint
clean.
