# BULK-001b — CSV manifest import, and a way to start a batch without a browser

## What this completes

BULK-001a established that the ingestion substrate already existed and that
BULK-001 was therefore much smaller than its ticket assumed. It listed three
things genuinely outstanding. This PR delivers two of them:

1. **CSV manifest import** — treated as import evidence, never as canonical
   catalogue data.
2. **An entry point that starts a batch** — `php artisan sanitube:import`.

The third, UI-004, renders the progress read model and is a separate ticket.

No parallel import path was built. There is still exactly one batch table, one
item table, one ingestion key and one idempotency guarantee.

## The design decision that shapes everything else

**A manifest is evidence, not authority.**

An operator's spreadsheet was exported from wherever their catalogue lived
before — a previous distributor's back office, a years-old export, somebody's
memory. Every value in it may be stale, and the platform has no way to tell
which. So nothing a manifest says is written into the catalogue by the import.
It travels to the `TrackCandidate`, where a reviewer sees it beside what the
file itself contains.

Concretely, after importing 900 files with a manifest naming 900 ISRCs:

- `tracks` is still empty.
- `external_identifiers` is still empty.
- 900 candidates carry the manifest under `metadata.manifest`.

Two tests assert exactly that, because it is the claim most likely to be
eroded by a later convenience.

The one place the manifest does win is `suggested_title`. A title somebody
typed deliberately is a better guess than one read off a filename — but it is
still a guess, which is why it lands in the field named for guesses, with
`metadata.suggested_title_source` recording which of the two it came from. "The
operator said so" and "we parsed it out of `01 - track.wav`" deserve different
amounts of trust from a reviewer, and a candidate that does not say which it
has is a candidate that gets both wrong.

## Refusals

**A bad row is refused; a bad file is fatal.** Nine hundred lines with three
mistakes import eight hundred and ninety seven — anything else makes the
feature useless for the job it exists for. The three come back with line
numbers, a machine-readable code and a sentence.

What refuses a single row:

| Code | When |
| --- | --- |
| `MISSING_REFERENCE` | the row names no object |
| `DUPLICATE_REFERENCE` | the same object is named on two lines — **both** refused |
| `DUPLICATE_ISRC` | two lines claim one ISRC — all claimants refused |
| `MALFORMED_IDENTIFIER` | the ISRC or UPC will not normalise |
| `MALFORMED_NUMBER` | disc or track number is not a whole number ≥ 1 |
| `UNACCEPTABLE_REFERENCE` | the source itself refuses that key (e.g. SaniTube's own managed prefixes) |

What refuses the whole file: no header, no reference column, two columns
meaning the same field, more rows than the configured ceiling.

Three of those deserve their reasoning stated.

**A malformed identifier refuses its row rather than being dropped.** The bytes
would import perfectly well without it, and that is exactly the problem: the
operator stated an ISRC, the platform would silently decide it did not count,
and the recording would sit in the catalogue looking as though none had ever
been claimed for it. Refusing is recoverable — fix the spreadsheet, re-run, and
the re-run is idempotent. A silent drop is not recoverable, because nobody
knows it happened.

**A reference named twice refuses both lines, not just the second.** Two rows
describing the same object with different metadata is a question for whoever
wrote the manifest. Answering it by taking whichever came first is an arbitrary
decision presented as a result.

**`filename` is not accepted as the reference column.** Everyone's file is
called `master.wav`. Accepting a column of them as the thing to fetch would
encourage precisely the mistake the ingestion key exists to prevent. A test
holds this.

## Identifier conflicts

`ManifestConflictDetector` compares what the manifest claims against what the
catalogue actively holds. It never resolves anything — it does not pick a
winner, attach an identifier or touch an existing one. It reports.

**An ISRC already active on another track is a conflict**, and the candidate is
created `NEEDS_REVIEW` with the holder's uuid recorded under
`metadata.manifest_conflicts`.

The failure it prevents is quiet rather than loud. Nothing in the import path
assigns identifiers — promotion mints none — so no wrong assignment ever
happens. What happens instead is that the recording lands as an ordinary
candidate, somebody promotes it, and the catalogue now holds two masters that
both claim one ISRC in the operator's records while only one says so in the
database. That surfaces months later in a royalty report that no longer
reconciles.

`NEEDS_REVIEW` at creation is deliberate and interacts with MED-001 on purpose:
it is a terminal status, so `SettleCandidateAfterAnalysis` leaves it alone and
the candidate cannot later be settled to `READY` and become quietly promotable.
The asset is still analysed either way — the person who has to decide wants the
duration and the loudness in front of them.

**A UPC already active on a release is not a conflict**, only an observation. A
manifest importing the twelve tracks of one album legitimately repeats one UPC
on all twelve rows, very often for a release the catalogue already has.
Treating that as a conflict would send an entire album to review for being an
album.

**A revoked ISRC is not a conflict.** Keeping revoked rows exists precisely so
a value can legitimately be in use again; treating history as a conflict would
make every deliberate reassignment look like a mistake.

Identifiers are normalised by the catalogue's own `ExternalIdentifierNormaliser`
rather than a second regex here. `FR-Z03-14-00001` and `frz031400001` are the
same ISRC, and the conflict check only works because normalisation happens
before the comparison. If validity were decided in two places, the import would
eventually accept what assignment later refuses — and the operator would find
out after the bytes were already in.

## Where the evidence is stored

On `ingestion_items.manifest_metadata`, before any work runs, and from there
onto the candidate.

Storing it on the item rather than only passing it through memory is what makes
a retry work. The worker that imports a file may be a different process on a
different day after a restart; the operator's CSV is not available to it. A
test asserts the round trip `ManifestRow → evidence → ManifestRow` is lossless
for exactly this reason.

`longText` with an array cast, never the JSON column type — MariaDB's is an
alias for `longtext utf8mb4_bin` while MySQL 8's is native binary, so one
migration would otherwise yield two types with two comparison semantics across
the support matrix.

`metadata` stays **null** when there was no manifest, rather than becoming an
empty object. "Nobody supplied one" and "one was supplied and said nothing" are
different facts, and a reviewer reading `{}` would reasonably conclude the
second.

## The command

```
php artisan sanitube:import --manifest=catalogue.csv
php artisan sanitube:import --prefix=library/2014
php artisan sanitube:import --reference=library/01.wav --reference=library/02.wav
php artisan sanitube:import --manifest=catalogue.csv --dry-run
```

Exactly one of `--prefix`, `--reference` or `--manifest`. None would mean
importing an entire store; more than one leaves two things naming the batch
with no rule for which wins, which is how an import quietly does something
nobody asked for. The same rule is enforced again inside `StartIngestionBatch`,
so it holds for callers that are not this command.

The command **queues** and does not import. Nine hundred files take hours to
fetch, hash, verify and analyse; a command that did that inline would be a
command nobody could finish running, and a browser tab that had to stay open
would be a tab somebody closes. A test asserts the jobs are pushed rather than
run.

`--dry-run` parses, reports and writes nothing — the interesting mistakes are
all made before any bytes move. It deliberately does **not** expand a prefix,
because a dry run that lists an object store is a dry run people stop trusting
to be free. When it truncates a list it says how many it did not show; a
truncated list that looks complete is how somebody concludes a batch is smaller
than it is.

## Real-world CSV

Handled because the file comes from a spreadsheet, not from a programmer: UTF-8
BOM (Excel writes one, and left in place it silently destroys the first column
name), `,` `;` tab and `|` delimiters sniffed from the header, header spellings
normalised (`Track Title`, `track_title`, `TRACK TITLE`) with aliases per field,
blank lines skipped.

Columns SaniTube has no field for are **kept** in `extra` and reported, not
discarded. An operator who put `mood` in their spreadsheet is entitled to have
it survive and to be told it was not understood.

## Guardrails proven

Eight regressions injected; **every one turned a test red** before the
implementation was trusted:

| Injected regression | Caught by |
| --- | --- |
| `active()` dropped from the ISRC lookup — revoked identifiers count as conflicts | a revoked isrc is not a conflict |
| a manifest conflict no longer holds the candidate | an isrc the catalogue already holds stops the candidate |
| a malformed identifier silently dropped instead of refusing the row | 5 tests across all three files |
| the first of two rows naming one reference kept | a reference named twice refuses both lines |
| one ISRC allowed on two rows | one isrc claimed by two rows refuses both |
| `filename` accepted as the reference column | a filename column is not accepted as the reference |
| manifest allowed beside a prefix | a manifest may not be combined with a prefix or references |
| manifest evidence not persisted on the item | 3 tests |

## Tests

42 new tests. **766 PHP tests / 3112 assertions**, 1 skipped (the real ffprobe
run), plus 16 component tests. PHPStan level 6 clean, no baseline. Pint clean.
vue-tsc clean. Vitest 16 passing. Frontend build clean.

Included: a 900-row synthetic manifest with three deliberately broken rows
placed in the middle rather than at the end — the interesting failure is the
one that could abandon the rows after it. 897 accepted, 3 refused, 897 items in
the batch.

## Finding: re-running a manifest costs storage

Not a defect introduced here, and **not changed here**, but it is now much
easier to hit and belongs on the record.

Re-running an import of the same reference — the normal way an operator
recovers from a partial import — writes a **second asset row and a second
stored object**, which the checksum then resolves to the first one. Nothing
reaches the catalogue: no Track, no identifier, and the candidate is created
`DUPLICATE` pointing at the original asset. But 900 files re-run means 900
objects stored twice.

The existing behaviour is defensible: ING-001 re-reads the source rather than
assuming a reference imported yesterday still holds the same bytes today, and
an object genuinely can be replaced under its key. Skipping on the ingestion
key alone would silently miss that replacement — a data-loss trade in the
opposite direction.

The fix is therefore not obvious and is not a manifest concern. Recorded as
`bulk_reimport_storage_cost` under known limitations, with the trade-off
stated, for a ticket that can measure it and choose deliberately. Two tests pin
the current behaviour so that whichever way it is later decided, the change is
visible.

## Architecture amendment

**None.** One additive nullable column, no invariant touched, no existing
behaviour changed. `StartIngestionBatch` gained an optional parameter;
`ProcessIngestionItem` gained a dependency and reads a column that is null for
every pre-existing row.

## Review

Not `REVIEW_REQUIRED` under the standing policy. It adds no authentication or
authorization semantics, no destructive storage behaviour, no public write API,
no external submission, and no identifier is assigned or revoked anywhere in
it — the identifier work here is strictly read-only detection that *prevents* a
future wrong assignment.
