# Session report — Phase 2 continuation, 2026-08-20

Transcription → AI enrichment → editorial → production planning → generation
hardening → Suno research → artwork → release packaging → distribution
research → doctor → backfill.
Twenty functional tickets across twenty-three pull requests (#67–#89), all
merged at 10/10. Three of those pull requests are bookkeeping and this report;
the other twenty are the tickets.

> **Extended in place, twice, and said so rather than quietly rewritten.** This
> report was first written at PROD-003 covering eleven tickets, extended at
> ART-002 covering fifteen, and is now final at twenty. The figures below
> are the last ones; the earlier numbers are gone. What is not gone is the
> reasoning — no verdict that was true at PROD-003 has been upgraded here, only
> extended.

---

## Status fields

| Field | Value |
|---|---|
| **START_MAIN_SHA** | `3c02d1bfbd84e23ad91aa3f38bfbf103f9a1e76f` |
| **END_MAIN_SHA** | `83aeecefb908abd03bc2bb3139cc68e30377801a` |
| **MERGED_TICKETS** | TRN-002 (#67), TRN-003 (#68), ENR-001 (#69), ENR-002 (#70), ENR-003 (#71), ENR-004 (#72), ENR-005 (#73), EDI-001 (#74), PROD-001 (#75), PROD-002 (#76), PROD-003 (#77), bookkeeping (#78), session report (#79), GEN-003 (#80), GEN-004 (#81), GEN-005 (#82), ART-001 (#83), ART-002 (#84), report update (#85), REL-003 (#86), DIST-003 (#87), OPS-003 (#88), ENR-006 (#89) |
| **OPEN_PRS** | none. Every pull request opened this session was merged at 10/10. |
| **REVIEW_REQUIRED** | none outstanding. Each PR was self-reviewed against §1 (component proof *and* production-path proof) and §2 (mutation discipline) before merge; the findings are in the per-ticket reports. |
| **TESTS** | 1676 passed, 1 skipped |
| **ASSERTIONS** | 14710 |
| **PHPSTAN** | `[OK] No errors` — level 6, no baseline |
| **PINT** | `passed` |
| **VUE_TSC** | exit 0 |
| **VITEST** | 23 passed, 3 files |
| **BUILD** | OK — 493.36 kB JS / 33.32 kB CSS, manifest written |
| **DB_MATRIX** | 10/10 on every one of the twenty-three pull requests. SQLite, MySQL 8.0, MariaDB 10.6, MariaDB 11.4 × PHP 8.2/8.3/8.4, each migrating, testing, rolling back and migrating again. |

Every figure above was produced by running the thing on a clean working tree at
`83aeece`, not quoted from a pull request page. The counts of tickets and pull
requests were checked against the merge log rather than added up by hand — an
earlier draft of this line said twenty-one tickets by counting the bookkeeping
pull requests among them.

---

## Subsystem status

| Field | Value | What that means |
|---|---|---|
| **R2_STATUS** | BLOCKED_EXTERNAL | The `r2` disk is configured and the S3 driver is exercised by two independent implementations. No real bucket has ever been written to (STO-002). |
| **DIRECT_UPLOAD_STATUS** | PARTIAL | `BeginDirectUpload`, `PresignedUpload`, `SupportsDirectUpload` and `DirectUploadController` exist and the HTTP path is production-path tested. **No browser calls it** — `grep` for the route in `resources/js` returns nothing. The feature is reachable by API, not by a person. |
| **PIPELINE_WIRING_STATUS** | READY | store → fingerprint → deduplicate → transcribe → suggest is wired by events and listeners, each step asserted as *pushed to the queue*, never run inline. |
| **MEDIA_WORKER_STATUS** | READY (optional) | `AnalyzeAsset` runs when FFmpeg is present. Absent FFmpeg produces a complete asset with no analysis, not a blocked one. |
| **FINGERPRINT_STATUS** | READY (optional) | Chromaprint when available; a missing fingerprint is not a failure of the asset. |
| **DEDUP_STATUS** | READY | `EvaluateAssetDuplicates` + `DecideDuplicate`; a decision is recorded with its actor. |
| **TRASH_STATUS** | READY | Trash columns, a `TrashAsset` service, a filtered index and a trash route. Reversible; nothing is destroyed by a duplicate decision. |
| **TRANSCRIPTION_STATUS** | READY internally / BLOCKED_EXTERNAL to certify | TRN-001/002/003. Manager, OpenAI adapter, eligibility, job, listener, backlog command, HTTP route. Idempotent per provider version. Automatic mode off by default. |
| **OPENAI_STATUS** | CONFIGURED_UNCERTIFIED | Adapters written against the published `openai-openapi` specification — `verbose_json` for transcription, `response_format: json_schema` with `strict` for enrichment. Never spoken to the live endpoint; no key in CI. Configuration alone can never set `CERTIFIED` — that is enforced in code. |
| **ANTHROPIC_STATUS** | CONFIGURED_UNCERTIFIED | Messages API; structured output via a forced single tool (`tool_choice: {type: tool, name}`) with `strict: true`, matched back **by name** so an unrelated server-tool block cannot be read as the answer. Never called for real. |
| **AI_ENRICHMENT_STATUS** | READY internally | ENR-001…005. Schema-required output, malformed output is a refusal, a call ceiling, a circuit breaker, accept/reject with audit, and a review screen that keeps four levels of truth apart. |
| **EDITORIAL_PROFILE_STATUS** | READY | EDI-001. One profile per imprint, guidance a prompt can carry, no half-made profile on refusal. |
| **PRODUCTION_PLAN_STATUS** | READY | PROD-001. Autonomy is an enum with five members, one of which is locked. Plan status distinguishes what an operator paused from what the platform exhausted. |
| **PRODUCTION_SLOT_STATUS** | READY | PROD-002. Unique index on (plan, occasion); a guarded `UPDATE` claim; a lost race is a success. |
| **GENERATION_STATUS** | READY with the fake provider, and hardened | PROD-003 added the inventory that precedes it. GEN-003 closed two defects in the existing path: the provider's exception message was being rendered in a browser — and on a query-string-authenticated provider that message *is* the credential — and a read-then-write idempotency check let two workers both pay for one generation. GEN-004 added a request ceiling and a circuit breaker, because PROD-001…003 built a planner whose purpose is to decide unattended that more music should exist. Real generation still has no provider. |
| **SUNO_STATUS** | RESEARCHED, BLOCKED_EXTERNAL | GEN-005. As of August 2026 Suno publishes **no API contract at all** — no console, no documentation, no request or response shapes, no limits. A developer API is being explored with curated partners; no timeline. ADR-0018 records the four conditions that would unblock an adapter and excludes reverse-engineered wrappers permanently, with the reason stated rather than treated as a rule to obey. `ProviderProvenanceTest` enforces it in CI. The rights research is in `docs/research/suno-2026-08.md`, opening with the fact that egress blocked every primary source. |
| **ARTWORK_STATUS** | READY internally / BLOCKED_EXTERNAL to certify | ART-001 built the measurer, the persisted measurement and the validator — a cover is now judged on what was measured, not on what its record says, with "unmeasured" a warning and never a pass. ART-002 added the image provider against the published OpenAI specification, and the feasibility check that refuses **before** spending. It found that this platform's default 3000px requirement and the specification's only square GPT-image size (1024) genuinely disagree, so generation refuses out of the box by design. Never certified against the real endpoint. |
| **RELEASE_PREPARATION_STATUS** | READY | REL-003. `ReleasePackage` is the one description that crosses to a distributor: assembled once from a release that passed validation, identifiers read active-only, a missing one null rather than invented, a track without a master an error rather than a silent omission. No location and no secret anywhere in it, asserted by walking the whole serialised structure. **Nothing calls it in production yet** — deliberately, so DDEX and the first adapter share one input rather than each walking the aggregate. |
| **DISTRIBUTION_RESEARCH_STATUS** | DONE, and it found a blocker | DIST-003. At least one distributor **does** publish a developer contract — unlike Suno — and this environment cannot read it: every distributor and DDEX host answers `403` at the egress proxy, verified by direct probe with `raw.githubusercontent.com` as a control. Also settled the money boundary before an adapter exists: their API covers royalties and payouts, and `FinancialScopeTest` now sweeps five modules so the field fails on the commit that adds it. |
| **DDEX_STATUS** | BLOCKED_EXTERNAL (environment) | Still no DDEX code, and now for a stated reason. ERN is a *published* standard whose schemas live on hosts this environment refuses. Community mirrors are reachable and were deliberately not used: a mirror is not the authoritative source, and treating one as the specification is the mistake ADR-0018 exists to prevent. REL-003 is the groundwork that survives it — when the schema is readable, the work is a mapping. |
| **DISTRIBUTOR_STATUS** | BLOCKED_EXTERNAL | Contracts, a manager, idempotency, validation, submission, reconciliation and manual resolution all READY against the fake distributor. `src/Distribution/Providers/` holds only `NullDistributor` — no vendor adapter (DIST-002). |
| **PORTABILITY_STATUS** | READY | ADR-0002 enforced by a migration-scanning test: `longText` + array cast, never `json()`, no database enums. ADR-0017's engine traps are covered — no unsigned subtraction in raw SQL, every non-nullable timestamp states its default, every comparison ordering has a deterministic tiebreak. |
| **SECURITY_STATUS** | READY internally / BLOCKED_EXTERNAL for a real penetration test | No secret in any log, payload, test or diagnostic. Vendor error **bodies are dropped entirely** rather than redacted, because a vendor error quotes the request back and redaction cannot mask catalogue data. Prompt injection is bounded by a fixed instruction constant. Authorization lives on routes; hidden buttons are presentation. |
| **SCALE_STATUS** | READY | ENR-006 closed the last backfill gap: every optional feature now has a way back over what was already there. **Re-import storage amplification does not "still need a measurement"** — that note was stale. ADR-0015 measured it at a factor of 2, pinned it with a passing test, and accepted it for V1 because every option that removes the second copy breaks AST-001's UUID-derived object key or destroys the record that a second import happened. Reclassified `POST_V1_STORAGE_OPTIMIZATION`. |
| **PRODUCTION_PATH_TEST_STATUS** | ENFORCED, and it kept earning its place | §1 was applied to all sixteen functional tickets. TRN-003, ENR-002 and ENR-004 each gained a caller because the service alone did not satisfy it. Then GEN-004, GEN-005, ART-001 and ART-002 each had a *surviving mutation* on exactly this: a guard wired into a caller with no test behind it. Four tickets in a row. The rule stated in ART-002's report is the session's clearest finding — **wiring a service into a caller is a separate claim from the service working, and needs its own assertion.** |

---

## Progress

**INTERNAL_PHASE2_PERCENT — 90%.**
The §48 order has twenty items. **Eighteen are merged.** The two that are not —
DDEX and a first distributor adapter — are not unstarted; they are blocked, and
by something this project cannot decide. See `ENVIRONMENT_EGRESS` below.

**PRODUCTION_PHASE2_PERCENT — 50%.**
Half, and the gap is the honest part rather than a hedge. Of the eighteen merged
items, nine work today on an installation that configures nothing external:
editorial profiles, production plans, slots, the pre-generation inventory, the
generation hardening, the artwork *validator*, the release package, the doctor
checks, and the financial-scope guardrail. The other nine need an OpenAI or
Anthropic account nobody has supplied, and **no adapter in this repository has
ever spoken to a live endpoint.**

Three of those numbers deserve naming rather than averaging:

- **GEN-005 lowered nothing and clarified everything.** Suno's blocker moved
  from "no credentials" to "no published contract exists" — worse, because
  nobody can hand over a key for an API that has not been released.
- **ART-002 ships refusing.** The image provider is complete, tested and wired,
  and on the shipped configuration it declines, because the platform's own
  3000px artwork requirement and the specification's only square GPT-image size
  do not agree. Finished code behaving correctly — and still not a working
  feature for an operator who changes nothing.
- **REL-003 has no production caller, on purpose.** The package exists so DDEX
  and the first adapter share one input; wiring it into `SubmitDelivery` before
  either exists would invent the calling convention twice. It is counted as
  merged and *not* counted as production-usable.

---

## EXTERNAL_BLOCKERS

| ID | Blocking |
|---|---|
| **AI-002** | Relying on transcription, enrichment or image generation in production. No OpenAI or Anthropic key was available or requested. Every adapter is proved against captured response shapes with stray requests blocked. |
| **GEN-002** | AI-generated catalogue in production. GEN-005 established the blocker is not credentials: **Suno publishes no API contract at all.** ADR-0018 records the four conditions that would unblock an adapter, and `ProviderProvenanceTest` enforces the exclusion of reverse-engineered wrappers in CI. |
| **ENVIRONMENT_EGRESS** *(new)* | Items 17 and 18 of the order — DDEX and a first distributor adapter. Every distributor and DDEX host answers `403` at this environment's egress proxy. **The specifications are public; this session cannot reach them.** Lifted by an environment allowing those hosts, or by the schemas being committed into `docs/`. Not a project decision. |
| **DIST-002** | Actually delivering a release. No distributor credentials, no adapter. Distinct from the row above: that one blocks the *code*, this one blocks *certification*. |
| **STO-002** | Production staging on real object storage. No bucket exercised. |
| **EXTERNAL_ADMIN_ACTION_REQUIRED** | The GitHub default branch is still `claude/verifier-repertoire-git-k29ft2` and should be `main`. No tool in this environment can change repository settings. |

## ADMIN_ACTIONS

One, and it is the last row above: **a human must change the repository's
default branch to `main` in GitHub settings.** Recorded once, per §43; not
repeated per ticket. Everything merged this session went to `main` explicitly,
so the wrong default has changed no outcome — it will mislead the next person
who clones.

---

## What went wrong, and what it cost

**BUGS_FOUND — 25. BUGS_FIXED — 25.**

Twelve through PROD-003, eight more through ART-002 (tabulated in the earlier
revision and in the per-ticket reports), and five after:

| # | Defect | Where it came from |
|---|---|---|
| 21 | A guardrail's own docblock failed it: the sentence promising the release package holds "no bucket" contains the word. **The second time this session a comment failed its own promise.** | Written during REL-003, caught by `StorageBoundaryTest`. |
| 22 | `PackageRelease` re-derived "is this a usable measurement" alongside the model's own rule — two guards for one fact, where breaking either left the other working. | Written during ART-001, found by a surviving mutation. |
| 23 | The doctor judged image sizes on the longest edge, so a `1792x1024` provider would report it could meet a 1500px requirement it cannot. | Written during OPS-003, found by a surviving mutation. |
| 24 | `EnrichBacklogCommand`'s docblock claimed eligibility was decided in one place while the query held a second copy of one of its rules. | Written during ENR-006, found by a surviving mutation. |
| 25 | **`docs/project-status.json` told future sessions to "review ADR-0015, pick an option, then implement it".** The decision was taken and accepted months earlier: the amplification is measured at a factor of 2, pinned by a passing test, and reclassified `POST_V1_STORAGE_OPTIMIZATION`. I carried the stale framing forward into ENR-006's report before checking the ADR, then corrected both. | Pre-existing, and briefly repeated by me. |

**TESTS_FOUND_DEFECTIVE — 22 tests, plus 1 defective harness.**

Sixteen through ART-002, six after: REL-003's contributor fixture (the factory
leaves `display_name` null, so legal name and any fallback to it are the same
string and the swap survived), OPS-003's all-square size fixtures, ENR-006's
missing limit-interaction case, and three read-boundary guards wired in with no
test behind them.

**The finding that survived the whole session: every single ticket had at least
one defective test, and not one of them was found by reading code.** Mutation
testing found all of them, and the harness itself was the first casualty — it
decided "killed" by grepping its own output for `fail`, a word test *names*
contain, so it reported kills on runs where everything passed. It now reads the
runner's exit code.

**The second finding, which took four tickets to become unmistakable:** GEN-004,
GEN-005, ART-001, ART-002 and OPS-003 each had a surviving mutation on the same
shape — *a guard wired into a caller with no test behind it*. Wiring a service
into a caller is a separate claim from the service working, and needs its own
assertion. A guard nobody checks is indistinguishable from no guard the next
time somebody refactors.

**Five mutations were withdrawn** rather than forced, each with its evidence:
GEN-004's N3, ART-001's A14, REL-003's R4 and R7 against genuinely redundant or
validator-unreachable branches, and GEN-005's P5 because it mutated a test
helper rather than an implementation. Twice the justification I expected to give
was checked and turned out to be false — PHPStan was indifferent to branches I
had assumed the type system required — and in both cases the report says so
instead of offering the reasoning I had planned.

---

## NEXT_ACTION

**Nothing in the §48 order remains that this environment can do.** Items 17 and
18 are blocked by `ENVIRONMENT_EGRESS`; the other eighteen are merged.

When that blocker lifts — an environment allowing `ddex.net` and a distributor's
developer host, or those specifications committed into `docs/` — the next work
is **DDEX, then a first distributor adapter**, in that order. REL-003 built the
input both consume, so what remains is a mapping rather than an archaeology of
the catalogue.

Four standing items an earlier session left are untouched and still open, and
`docs/project-status.json` now lists them without the stale fifth:

1. Accessibility, the part jsdom cannot reach — per-screen keyboard walkthroughs
   on real assistive technology, and responsive behaviour at four breakpoints
   across six locales. Needs a rendered browser.
2. AUDIT-002 — an honest actor identity for `src/Api`. A shared token names no
   person; recording those calls as *guest* misleads and as a *user* is false.
3. Real-host certification: `CPANEL_CERTIFICATION` and `VPS_CERTIFICATION`.
4. `POST_V1_STORAGE_OPTIMIZATION` — the ADR-0015 amendment, **as deliberate
   post-V1 work and not as a review**. The decision is already made.

Named in ticket reports rather than folded into a "done":

- **No image spend ceiling.** GEN-004 built one for music generation; images
  need the same, against the asset ledger.
- **No screen or route for artwork generation**, and none for the backfills. A
  button that spends money needs its own confirmation surface and authorization
  ticket.
- **`DeliveryDetailQuery::reason()` truncates but does not redact** — the same
  class of exposure GEN-003 closed for generation still exists in the
  distribution module.
- **No colour-profile inspection** for artwork; `getimagesize` cannot read an
  ICC profile, so "is this sRGB" is unanswered rather than guessed.

`docs/production-readiness.md` covers every module built this session under the
same four-value vocabulary, including one deliberately **NOT_READY** row: image
generation on the shipped configuration.

---

## Standing constraints honoured

Recorded because they are easier to violate quietly than loudly.

- Nothing in this session touched royalties, revenue, payouts, balance,
  accounting or any financial split. The call ceiling counts **calls**, holds no
  price and no currency, and a test scans the source for currency symbols with
  comments stripped.
- `AUTONOMOUS_RELEASE` is locked in code. `isAvailable()` returns false for it
  and `mayReleaseUnattended()` checks availability again rather than trusting the
  enum member. Three independent tests hold it shut.
- A `GenerationResult` never becomes a `Track`. Generated audio joins the review
  queue like anything else.
- No unofficial Suno wrapper. No unofficial automation against a distributor's
  web interface.
- Nothing hardcodes a domain, path, worker URL, bucket, provider, Redis, queue,
  PHP binary, FFmpeg path, timezone, language or distributor.
- Nothing was pushed to `main` directly. Twelve pull requests, twelve merges.
- No report was overwritten and no ticket ID was reused.
