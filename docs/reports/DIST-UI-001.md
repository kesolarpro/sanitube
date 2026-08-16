# DIST-UI-001 — Distribution operations

Three screens over DIST-001, and the interface for the one act on this platform
that cannot be undone.

## What was built

**`src/Ui/Queries/DeliveryIndexQuery.php`** — every handover this installation
has made or attempted. Ordered `(created_at, uuid)`, one `with` and one
`withCount` per page.

**`src/Ui/Queries/DeliveryDetailQuery.php`** — one delivery and every
conversation it has had. **Nothing in it talks to a distributor.**
`isAvailable()` and `isSandbox()` read configuration — the contract says so —
and everything else is a row SaniTube wrote itself. A screen that polled on
render would make refreshing a page an outbound request and would take the page
down with the distributor. Asking is `sync`, which is a button.

**`src/Ui/Queries/ReleaseDistributionQuery.php`** — where one release could go
and where it has already gone.

**`src/Ui/Http/Controllers/Distribution/`** — index, detail, send, actions. All
four actions go through DIST-001's services unchanged.

**`resources/js/Pages/Distribution/{Index,Show,Send}.vue`**,
`Types/distribution.ts`, and the `distribution` block in all six locales.

## Three states that are never drawn the same way

This is the whole design of the detail screen, and each pair is a way a local
record starts disagreeing with reality:

| | Means | Retryable |
|---|---|---|
| **Never checked** (`last_synced_at` null) | Nobody has asked the distributor. **Not** "nothing has changed" — it may have been accepted weeks ago. | n/a |
| **FAILED** | SaniTube could not reach the distributor. Not a verdict anybody gave. | yes, same idempotency key |
| **REJECTED** | The distributor looked at the package and said no. A verdict. | no |

`UNKNOWN ≠ ZERO`, `TIMEOUT ≠ REJECTED`. A screen that drew FAILED and REJECTED
with the same badge would undo DIST-001's central design decision.

## What never leaves the server

`external_release_id` identifies a record in somebody else's account.
`idempotency_key` is the token that makes a repeat submission recognisable —
a reader holding it could construct one. Neither is in any payload; the screen
shows `has_external_reference`, because the question a label actually has is
*"did this reach them"*.

`failure_reason` and each attempt's `summary` are truncated to one line at 200
characters. They are a distributor's statement about its own service, which is
the one class of provider message this platform passes through — but
`SubmitDelivery`'s outage path stores a raw exception message, and those quote
hosts and paths.

## Decisions worth recording

**The preflight creates nothing.** `validate` and `submit` are separate for the
reason DIST-001 gives — a label must see a verdict without anything being
handed over — and the controller deliberately does *not* call `open()`. A "let
me check" that left a DRAFT delivery row behind becomes a record a colleague
later reads as an intention. A mutation that adds the `open()` call is killed
by a test.

**The verdict is a GET answered with JSON.** It changes nothing, so a GET is
honest, and JSON keeps the answer beside the destination it is about — the same
pattern the release builder's pickers use. It also means a person can fix
something, ask again, and compare without a page round trip putting them back
at the top.

**`none` is not offered as a destination.** It is the name of *not having* a
distributor; offering it would be offering a submission that cannot happen.

**A sandbox destination is labelled, not hidden.** A rehearsal is legitimate,
which is exactly why it must be visible: a sandbox delivery that looks like a
real one is discovered weeks later, when stores have nothing.

**A delivery naming a distributor that is gone still opens.** A delivery
outlives a configuration change; the provider is reported `known: false` rather
than throwing, because 500ing here hides the very record somebody came to read.

**Distribution writes are behind `can.role:distribute`, not `catalogue`.** It is
the one ability that is not simply "can write": a member who can assemble a
release still cannot send it to a store.

## What the tests hold

32 tests, 109 assertions in `tests/Feature/Ui/DistributionScreensTest.php`.

`rendering_a_delivery_never_asks_the_distributor_anything` proves the no-probe
rule by putting the fake distributor into an outage — every outbound call
throws — and asserting all three screens still return 200. A counter would have
been a weaker claim.

### Mutation pass — 20 injected, 20 killed

| # | Regression injected | Test that turned red |
|---|---|---|
| M1 | the external reference is published | nothing identifying somebody else's account |
| M2 | the index publishes the idempotency key | nothing identifying somebody else's account |
| M3 | a failure reason is passed through whole | a failure is reported without its URL |
| M4 | an unsynced delivery is dated instead of unknown | a delivery nobody has asked about says so |
| M5 | a failed delivery treated as final | an outage leaves it failed, never rejected |
| M6 | sync offered before anything was handed over | checking a never-delivered record asks nobody |
| M7 | takedown offered before anything is live | a takedown cannot be asked for yet |
| M8 | an unresolvable distributor takes the page down | a delivery naming a gone distributor opens |
| M9 | `none` offered as a destination | the send screen never offers the disabled one |
| M10 | a draft release offered for submission | a draft cannot be handed over |
| M11 | an unavailable distributor offered anyway | neither a verdict nor a submission |
| M12 | sandbox mode not reported | a sandbox destination is labelled |
| M13 | the preflight opens a delivery record | asking for a verdict creates no delivery |
| M14 | writes lose `can.role:distribute` | a member may watch and do nothing |
| M15 | a refusal swallowed as success | submitting twice hands it over once |
| M16 | the list ordered by submission date | ordered by when the record was made |
| M17 | the release relation loaded per row | a page costs a bounded number of queries |
| M18 | a foreign cursor paginated through | the query refuses it on its own |
| M19 | the form request stops checking the cursor | a foreign cursor is refused |
| M20 | an unknown distributor answered as valid | an unknown distributor is refused by code |

**M16 survived the first run, and that was a weak test.** Both fixtures had a
null `submitted_at`, so ordering by it fell through to `uuid` and happened to
agree. The fixtures now carry submission dates in the opposite order to
creation — which is the real case, since a delivery is opened when somebody
decides to send something and submitted whenever the package is finally ready.

## Scope correction applied to the navigation

`publishing` and `royalties` are removed from the sidebar. Both were
placeholders for financial administration, and the 2026-08-16 correction takes
finance out of V1 entirely. A sidebar entry for a screen the product will never
build is a promise, not a roadmap; their translation keys and icons went with
them.

`rights` stays, unavailable. Credit and ownership *metadata* — author, composer,
publisher as a name, the ℗ and © notices — is required for distribution and is
not a right to money.

## Not in this ticket

**No real distributor.** `DIST-002` stays `BLOCKED_EXTERNAL`: no credentials, no
adapter, no delivery ever made to a real service. Everything here is exercised
against the shipped fake, which is shipped rather than test-only precisely so
the delivery engine can be demonstrated and reviewed without an account.

**DIST-001-H1** — what to do when a submission's outcome is genuinely unknown —
is untouched and remains `REVIEW_REQUIRED`.

**REL-002 still applies.** The preflight's errors and warnings are the same
domain sentences the release builder shows, so they render in English on every
locale. `ValidateDelivery` merges `ValidateRelease`'s output with its own, and
`SubmitDelivery::wasUnreachable()` matches on the text — which is exactly why
REL-002 is a domain ticket rather than something a UI ticket does quietly. This
is the second screen to hit it, as predicted.

## Gate

| Check | Result |
|---|---|
| PHPUnit | 950 passed, 1 skipped, 4130 assertions (was 918 / 3955) |
| PHPStan level 6+ | no errors, no baseline, no ignores |
| Pint | passed |
| vue-tsc | passed |
| Vitest | 16 passed |
| Production build | built |
| Localisation gate | 33 passed — six locales complete |
| Mutation pass | 20 injected, 20 killed |

One pre-existing assertion moved: `InterfaceShellTest` marked Distribution as
unbuilt. Its own comment says the assertion moves as screens land; it now
asserts Distribution is reachable and points `analytics` at the unbuilt claim.
