# DIST-003 — Distribution and DDEX research

**Research only, plus one guardrail. Items 17 and 18 of the order cannot be
started from this environment, and this records why with evidence.**

---

## The finding

Unlike Suno, at least one distributor **does** publish a developer contract.
This session cannot read it: every distributor and DDEX host is refused by the
environment's network egress policy.

That is a materially different blocker from GEN-005's. Suno has published
nothing, so nobody could supply it. These specifications exist and are public;
this session simply cannot reach them. One is a vendor decision, the other is a
configuration of where this work runs.

## Evidence, with a control

| Host | Result |
|---|---|
| `ddex.net`, `service.ddex.net`, `kb.ddex.net` | `CONNECT tunnel failed, response 403` |
| `developer.toolost.com`, `api.toolost.com` | `CONNECT tunnel failed, response 403` |
| `www.tunecore.com` | `CONNECT tunnel failed, response 403` |
| `raw.githubusercontent.com` *(control)* | **`HTTP 200`** |

The control is what makes the rest mean anything — a probe where everything
fails proves only that probing fails. It is also how ART-002 was possible: the
OpenAI specification is mirrored there, so it could be read in full rather than
summarised.

## Why a search summary was not enough

Web search works where fetching does not, and it reports that Too Lost publishes
a REST API with OAuth covering catalogue, releases, distribution, analytics and
webhooks. **That is second-hand and is not a basis for code.** §5 forbids
guessing endpoints, formats, limits and response fields; ADR-0018 requires a
contract the supplier publishes *and that we can read*. A description of an API
is neither.

The same applies to DDEX, and more sharply. ERN is a published standard, but a
wrong element name, namespace version or cardinality produces a document a
distributor rejects — and that surfaces at delivery, the one operation this
platform must never get wrong twice. There is no partial credit in schema
conformance.

**Community mirrors of the DDEX schemas exist on hosts that are reachable, and
were deliberately not used.** A mirror is not the authoritative source. Reading
a copy somebody else uploaded and treating it as the specification is the same
mistake as reading a reverse-engineered wrapper's documentation and treating it
as the vendor's — the mistake ADR-0018 exists to prevent.

---

## The part that is not just a finding

Too Lost's API is reported to cover **royalties, splits and payouts** alongside
delivery. SaniTube must never implement any of those.

The temptation is real precisely because that data will be *right there* in
responses an adapter already receives. Dropping it has to be a deliberate,
visible act rather than an incidental one — so `FinancialScopeTest` now sweeps
`src/Distribution`, `src/Releases`, `src/Production`, `src/Editorial` and
`src/Enrichment` for financial vocabulary, with comments stripped.

**Written now rather than when it is nearly too late.** A guardrail that exists
before the adapter fails on the commit that adds the field, which is when it is
cheap. One added afterwards has to argue with code somebody has already shipped.

It differs from the per-ticket money tests already in the suite: those are
promises about files their authors were thinking about, and this sweeps whole
modules, so a *new* file is covered without anybody remembering to extend a
list.

Three of its four tests exist because of things that went wrong earlier in this
session:

- **`every_named_module_is_actually_reached`** — "no offenders" is also what a
  scan that read nothing returns. A total-file threshold would be a magic number
  that drifts; what actually goes wrong is a module being renamed and silently
  contributing zero files while the sweep still passes. Two guardrails did
  exactly that this session.
- **`the_scan_would_actually_catch_something`** — the guardrail's own guardrail.
  A scanner is evidence only if it fails on the thing it exists to find.
- **`comments_are_stripped_before_scanning`** — twice this session a docblock
  has failed a guardrail by naming the very thing it asserted the absence of.

---

## §2 — mutation results

Three mutations. **All three killed.**

| # | Mutation | Verdict |
|---|---|---|
| D1 | a royalty field added to `DistributorSubmission` — the exact thing this exists to catch | killed |
| D2 | a module silently dropped from the sweep | killed |
| D3 | comments no longer stripped | killed |

D1 is the one that matters: it adds the field an adapter author would plausibly
add, to the file they would plausibly add it to, and the guardrail fails.

---

## What would unblock items 17 and 18

Any one of:

1. **An environment whose egress allows `ddex.net`, `kb.ddex.net` and the chosen
   distributor's developer host.** A configuration of the execution
   environment, not a project decision.
2. **The specifications committed into the repository** — the ERN XSDs and the
   distributor's OpenAPI document under `docs/`, exactly as the OpenAI
   specification was usable because it is mirrored somewhere reachable.
3. **Distributor credentials and a sandbox** — needed for certification
   regardless, already tracked as DIST-002.

The first two unblock the *code*; the third unblocks *certification*. They are
not the same thing, and DIST-002 has only ever covered the third.

## New blocker recorded

**`ENVIRONMENT_EGRESS`** — reading any distributor or DDEX specification, and
therefore items 17 and 18. Lifted by an environment allowing those hosts, or by
the specifications being committed. **Not a project decision**, which is why it
is recorded separately from DIST-002 rather than folded into it.

---

## What this ticket did not do

- **No adapter.** No contract could be read.
- **No DDEX document.** Same reason, and building one from recollection is not
  an option.
- **No use of a community schema mirror**, deliberately.
- **No partner or developer-account application.** A commercial decision for a
  human, not something to submit autonomously.

---

## Verification

- `1661 passed, 1 skipped, 14638 assertions`
- PHPStan `[OK] No errors` (level 6, no baseline)
- Pint `passed`
- No migration and no production behaviour changed.
