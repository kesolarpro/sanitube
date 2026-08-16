# REL-UI-001 — The release builder

A release list and a seven-section builder, as a surface over REL-001. Nothing
in this ticket decides anything the domain already decides.

## What was built

**`src/Ui/Queries/ReleaseIndexQuery.php`** — the list. Ordered `(created_at,
uuid)` like every other list here, and deliberately *not* by release date: a
label entering a back catalogue produces records whose dates are years apart and
in no particular order, and a list that reshuffles as dates are filled in is one
nobody can work down. `track_count` and `artist_count` come from one `withCount`
per page rather than a query per row.

**`src/Ui/Queries/ReleaseDetailQuery.php`** — everything the builder needs, in
one payload. Three things are *asked of the domain rather than decided here*,
and that is the whole design of the class:

- **What may be edited** comes from `ReleaseMutationPolicy`. A screen with its
  own opinion about what a SUBMITTED release still accepts would be a second
  copy of I4, kept in the least trustworthy place.
- **What is wrong** comes from `ValidateRelease`, errors and warnings kept
  apart: a release with no cover cannot be delivered, one with no catalogue
  number is merely incomplete.
- **Whether it may be marked ready** comes from `Release::readinessProblems()`
  — the same list `markReady()` throws on. The button is disabled by exactly
  the facts that would refuse the request.

**`src/Ui/Http/Controllers/Releases/`** — index, builder, actions, pickers.
Nine write actions, every one of them through `ReleaseBuilder`, and **none of
them writes `status`**. Readiness is earned by passing validation:
`markReady()` re-runs the invariants and throws if they do not hold, so a
controller that set `status = READY` would be skipping I4 and I9 while looking
identical from the outside.

**`src/Ui/Queries/ReleasePickerQuery.php`** and three JSON endpoints — the
tracks, artists and artwork a person can actually choose. Bounded at 20 rows
with no pagination, because a picker that returns a catalogue is a picker that
hangs a browser on an installation with real data in it. Each list is filtered
by what the *domain* would accept: tracks already on the release are excluded
because `addTrack()` would refuse them, and artwork is restricted to the
VERIFIED ARTWORK pair `setCover()` refuses without. Offering a choice that is
always refused is a bug with a nice error message on it.

**`resources/js/Pages/Releases/{Index,Show}.vue`**, `Components/Ui/ResourcePicker.vue`,
`Types/releases.ts`, and the `releases` block in all six locales.

## Decisions worth recording

**The pickers are behind the write role, not merely behind authentication.**
They exist to fill in editing forms. A reader who cannot add a track has no use
for the list of tracks they could add, and the smaller surface is free.

**`releases/options/...` rather than `releases/artists`.** A two-segment path
would be matched by `releases/{release}` and answered with a 404 from model
binding instead of the list — a bug that only appears once there is data.

**A typed `%` is text, not syntax.** Left as syntax, a search for `%` matches
the entire catalogue and the cap silently becomes the whole answer: a search
that looks like it worked and did not. Escaped with an explicit `ESCAPE`
clause rather than by relying on a default, because there is no shared default
— MySQL treats a backslash as an escape inside LIKE and SQLite does not. The
escape character is `!` rather than the conventional backslash for the same
reason: MySQL also treats a backslash as an escape inside *string literals*, so
`ESCAPE '\'` is an unterminated string there and a valid clause in SQLite.

**"At least one PRIMARY artist" is not restated in the form request.** It is a
property of the credit list, not of the form, and it lives in
`ReleaseBuilder::setArtists()`. The screen warns before sending, which is
kinder than a refusal and is not a substitute for one.

**Reordering sends the whole disc, never the pair that swapped.**
`UNIQUE(release_id, disc_number, track_number)` makes numbering a property of
the list; the builder parks every row above the occupied range before writing
final numbers, and a two-row swap sent as two updates collides in the middle.

## What the tests hold

38 tests, 129 assertions in `tests/Feature/Ui/ReleaseBuilderScreensTest.php`.
The four claims: nothing writes `status`; `actions` is presentation and never
authorisation; the policy is asked rather than copied; identifiers are read and
never minted.

### Two real bugs the tests found

**The detail payload crashed on any release that had an artist.** The pivot
casts `role` to `ReleaseArtistRole`; the query read it as a string. It worked
in review and would have 500'd on the only state the screen is ever looked at
in.

**A `%` typed into a picker matched the entire catalogue.** Found by the test
written to assert the opposite, before the escaping was correct.

### Mutation pass — 18 injected, 18 killed

Each was applied alone, the suite run, and the file restored.

| # | Regression injected | Test that turned red |
|---|---|---|
| M1 | `can_mark_ready` ignores the domain readiness list | readiness follows the domain's own list |
| M2 | `markReady` assigns the status instead of earning it | marking an incomplete release ready |
| M3 | write routes lose `can.role:catalogue` | a member may read and change nothing |
| M4 | the screen decides everything is editable | hiding a control never stops a request |
| M5 | the picker treats a typed wildcard as syntax | a wildcard is treated as text |
| M6 | the track picker offers tracks already on the release | the track picker offers only addable tracks |
| M7 | the picker offers unverified artwork | the artwork picker offers only usable covers |
| M8 | the list is ordered by release date | ordered by when the record was made |
| M9 | a foreign cursor is paginated through | the query refuses a foreign cursor on its own |
| M10 | revoked identifiers shown beside live ones | only identifiers in force are shown |
| M11 | a missing release date filled in with today | a release with no date says so |
| M12 | counts dropped from the page query | the list counts what is on each release |
| M13 | the tracklist returned in insertion order | reordering writes the whole order |
| M14 | a refusal swallowed and reported as success | the same recording cannot be added twice |
| M15 | the pickers leave the write role | the pickers are behind the write role |
| M16 | an unknown track uuid passed to the domain | an unknown track is refused by code |
| M17 | the credit role read back as a plain string | a credit list is saved in order |
| M18 | the form request stops checking the cursor shape | a foreign cursor is refused |

**M9 survived the first run, and that was a defective test.** The HTTP 422 came
from the form request's rule, so deleting the guard *inside the query* left the
test green — it was proving the request and nothing else, while the query is
what the next caller will reach for. A direct test on the query was added, and
M18 was added to cover the request's own rule. Both now fail on their mutation.

## Known limitation — REL-002

Validation errors and warnings render **in English on every locale**, while the
rest of the screen is translated. They are sentences produced by
`ValidateRelease` and `Release::readinessProblems()`, not codes.

This is not an oversight and it is not fixable from a UI ticket. The same
strings are imploded into delivery-attempt records and matched on by
`SubmitDelivery::wasUnreachable()`. Turning them into codes changes delivery
semantics, so it is raised as **REL-002** — a backend ticket that gives the
domain a `code + context` problem representation while keeping a rendered
sentence for logs. DIST-UI-001 will hit the same wall for delivery validation.

## Scope correction recorded

`docs/project-status.json` now carries the 2026-08-16 correction: SaniTube is a
catalogue and distribution platform and **not a financial one**. ROY-001,
RIGHTS-001 (in its financial form) and PUB-001 are marked `OUT_OF_SCOPE_V1`. No
Revenue, Royalty, Payout or Ledger module was ever built, so nothing was
deleted — the tickets are reclassified. Credit and ownership information stays
as catalogue metadata and triggers no calculation; the ℗ field on this screen is
labelled as a notice for exactly that reason.

The progress weighting was replaced with the nine-block one the correction
defines. 53 → 55 on a changed denominator, so the two are not comparable: the
rights/royalties block (weight 10, at zero) left V1 and its weight moved to
installer/deployment/backup, now the largest unfinished block.

## Gate

| Check | Result |
|---|---|
| PHPUnit | 918 passed, 1 skipped, 3955 assertions (was 880 / 3734) |
| PHPStan level 6+ | no errors, no baseline, no ignores |
| Pint | passed |
| vue-tsc | passed |
| Vitest | 16 passed |
| Production build | 860 modules, built |
| Localisation gate | 33 passed — six locales complete |
| Mutation pass | 18 injected, 18 killed |
