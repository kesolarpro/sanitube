# Session report — Phase 2 continuation, 2026-08-20

Transcription → AI enrichment → editorial → production planning → generation
hardening → Suno research → artwork.
Sixteen functional tickets across eighteen pull requests (#67–#84), all merged
at 10/10.

> **Extended in place, and said so rather than quietly rewritten.** This report
> was first written at PROD-003 and covered eleven tickets. The session
> continued past it, so the figures and the status fields below are the *final*
> ones and the earlier numbers are gone. What is not gone is the reasoning: no
> verdict that was true at PROD-003 has been upgraded here, only extended.

---

## Status fields

| Field | Value |
|---|---|
| **START_MAIN_SHA** | `3c02d1bfbd84e23ad91aa3f38bfbf103f9a1e76f` |
| **END_MAIN_SHA** | `d44a7df1ff3fde4bcbe096f6e44f479184176edc` |
| **MERGED_TICKETS** | TRN-002 (#67), TRN-003 (#68), ENR-001 (#69), ENR-002 (#70), ENR-003 (#71), ENR-004 (#72), ENR-005 (#73), EDI-001 (#74), PROD-001 (#75), PROD-002 (#76), PROD-003 (#77), bookkeeping (#78), session report (#79), GEN-003 (#80), GEN-004 (#81), GEN-005 (#82), ART-001 (#83), ART-002 (#84) |
| **OPEN_PRS** | none. Every pull request opened this session was merged at 10/10. |
| **REVIEW_REQUIRED** | none outstanding. Each PR was self-reviewed against §1 (component proof *and* production-path proof) and §2 (mutation discipline) before merge; the findings are in the per-ticket reports. |
| **TESTS** | 1646 passed, 1 skipped |
| **ASSERTIONS** | 14530 |
| **PHPSTAN** | `[OK] No errors` — level 6, no baseline |
| **PINT** | `passed` |
| **VUE_TSC** | exit 0 |
| **VITEST** | 23 passed, 3 files |
| **BUILD** | OK — 493.36 kB JS / 33.32 kB CSS, manifest written |
| **DB_MATRIX** | 10/10 on every one of the eighteen pull requests. SQLite, MySQL 8.0, MariaDB 10.6, MariaDB 11.4 × PHP 8.2/8.3/8.4, each migrating, testing, rolling back and migrating again. |

Verified on a clean working tree by running the suite, not quoted from a pull
request page.

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
| **RELEASE_PREPARATION_STATUS** | PARTIAL | `ReleaseBuilder` and `ValidateRelease` exist and are READY from V1. The Phase 2 preparation work (packaging a validated release for a distributor) has not begun. |
| **DISTRIBUTION_RESEARCH_STATUS** | NOT_STARTED | Not touched this session. |
| **DDEX_STATUS** | NOT_STARTED | No DDEX code, config or document anywhere in the repository. |
| **DISTRIBUTOR_STATUS** | BLOCKED_EXTERNAL | Contracts, a manager, idempotency, validation, submission, reconciliation and manual resolution all READY against the fake distributor. `src/Distribution/Providers/` holds only `NullDistributor` — no vendor adapter (DIST-002). |
| **PORTABILITY_STATUS** | READY | ADR-0002 enforced by a migration-scanning test: `longText` + array cast, never `json()`, no database enums. ADR-0017's engine traps are covered — no unsigned subtraction in raw SQL, every non-nullable timestamp states its default, every comparison ordering has a deterministic tiebreak. |
| **SECURITY_STATUS** | READY internally / BLOCKED_EXTERNAL for a real penetration test | No secret in any log, payload, test or diagnostic. Vendor error **bodies are dropped entirely** rather than redacted, because a vendor error quotes the request back and redaction cannot mask catalogue data. Prompt injection is bounded by a fixed instruction constant. Authorization lives on routes; hidden buttons are presentation. |
| **SCALE_STATUS** | PARTIAL | Queueing properties asserted where they belong (900-file import). Re-import storage amplification (BULK-001c) still needs a measurement, not a guess. No backfill tooling for the new modules. |
| **PRODUCTION_PATH_TEST_STATUS** | ENFORCED, and it kept earning its place | §1 was applied to all sixteen functional tickets. TRN-003, ENR-002 and ENR-004 each gained a caller because the service alone did not satisfy it. Then GEN-004, GEN-005, ART-001 and ART-002 each had a *surviving mutation* on exactly this: a guard wired into a caller with no test behind it. Four tickets in a row. The rule stated in ART-002's report is the session's clearest finding — **wiring a service into a caller is a separate claim from the service working, and needs its own assertion.** |

---

## Progress

**INTERNAL_PHASE2_PERCENT — 70%.**
The §48 order has twenty items. Fourteen are merged: through PROD-003, then
generation hardening (GEN-003, GEN-004), Suno research (GEN-005), the artwork
validator (ART-001) and the image provider (ART-002). Six remain: release
preparation, distribution research, DDEX, a first viable distributor adapter,
scale/backfill/hardening, and docs/doctor. Counted as items merged, not as lines
written.

**PRODUCTION_PHASE2_PERCENT — 40%.**
Lower than the internal figure, and the gap is the honest part rather than a
hedge. Of the fourteen merged items, eight depend on an external account nobody
has supplied: transcription and enrichment need an OpenAI or Anthropic key, the
image provider needs one too, and no adapter in this repository has ever spoken
to a live endpoint. Editorial profiles, production plans, slots, the
pre-generation inventory, the generation hardening and the artwork *validator*
work today with no external dependency at all — the validator in particular is
fully usable on covers that were uploaded rather than generated.

Two of those numbers deserve naming rather than averaging:

- **GEN-005 lowered nothing and clarified everything.** Suno moved from "no
  credentials" to "no published contract exists", which is a different and worse
  blocker: nobody can hand over a key for an API that has not been released.
- **ART-002 ships refusing.** The image provider is complete, tested and wired,
  and on the shipped configuration it declines to generate, because the
  platform's own 3000px artwork requirement and the specification's only square
  GPT-image size do not agree. That is finished code behaving correctly, not
  unfinished code — but it is not a working feature for an operator who changes
  nothing, and counting it as one would be exactly the dishonesty this field
  exists to prevent.

---

## EXTERNAL_BLOCKERS

| ID | Blocking |
|---|---|
| **AI-002** | Relying on transcription or enrichment in production. No OpenAI or Anthropic key was available or requested. Both adapters are proved against captured response shapes with stray requests blocked. |
| **GEN-002** | AI-generated catalogue in production. No music generation credentials, no adapter. |
| **DIST-002** | Actually delivering a release. No distributor credentials, no adapter. |
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

**BUGS_FOUND — 20. BUGS_FIXED — 20.**

Twelve were found through PROD-003 and are tabulated in the per-ticket reports.
Eight more followed, and they are worth listing because three were **pre-existing
defects in merged code**, not mistakes made while writing the ticket:

| # | Defect | Where it came from |
|---|---|---|
| 13 | A provider's exception message was written into `failure_reason` and rendered on the Studio screen and the v1 API. A client exception quotes the request; on a query-string-authenticated provider **that message is the credential**. | Pre-existing (GEN-001). |
| 14 | The submission idempotency check was a read-then-write on a column that is null for the whole duration of the call it guards, so two workers could both pay for one generation. | Pre-existing. |
| 15 | `poll_count` incremented by reading and adding one, so two concurrent polls advanced the runaway-loop bound by one instead of two. | Pre-existing. |
| 16 | **`assets.width` and `assets.height` are columns nothing has ever written.** Three screens read them, so they were blank on every production installation — and the suite never noticed because the asset factory sets `width => 3000`. | Pre-existing, masked by a fixture. |
| 17 | A generated cover would never have been measured: `store()` leaves an asset at STORED and the measurement listener fires on verification. | Written during ART-002, caught by an assertion. |
| 18 | A hardcoded `api.openai.com` default in `config/artwork.php`, against the standing "nothing hardcodes a domain" constraint. | Written during ART-002, caught by the portability guardrail. |
| 19 | A method that redacted an exception message and then discarded it — dead code with a docblock explaining itself. | Written during ART-002, caught by PHPStan. |
| 20 | `ValidateArtwork` passed a list into an audit context that keeps only string keys, so it would have arrived empty. | Written during ART-001, caught by PHPStan. |

**TESTS_FOUND_DEFECTIVE — 16 tests, plus 1 defective harness.**

Nine through PROD-003, seven after: three surviving mutations in GEN-004 (a test
that got the right answer for the wrong reason, a submit-path check with no test
at all, and a fallback never exercised), two in GEN-005 (a scan that globbed with
a doubled wildcard and, when it was found, covered **368 of the repository's
then 634 source files** while passing, and an
assertion that reported on the environment rather than the code and *could never
have failed*), and one each in ART-001 and ART-002 of the same shape.

**Every single ticket in this session had at least one defective test, and not
one of them was found by reading code.** Mutation testing found all of them. The
harness itself was the first casualty: it decided "killed" by grepping its own
output for `fail`, a word test *names* contain, so it reported kills on runs
where everything passed. It now reads the runner's exit code, and every mutation
in this session was re-run under it.

Three mutations were **withdrawn** rather than forced: GEN-004's N3 and
ART-001's A14 against genuinely redundant branches, and GEN-005's P5 because it
mutated a test helper rather than an implementation, which is outside §2's
discipline. Each is recorded with the evidence — including one case where the
justification I expected to give (that the type system required the branch)
turned out on checking to be false, and is not offered.

---

## NEXT_ACTION

Item 15 of the §48 order: **release preparation** — packaging a validated
release for a distributor. It is internal work with no external dependency,
which is the right place to spend effort while every provider-facing item waits
on an account.

Then: distribution research, DDEX, a first viable distributor adapter,
scale/backfill/hardening, docs/doctor.

Two smaller things named in ticket reports rather than left implicit, neither
silently folded into a "done":

- **No image spend ceiling.** GEN-004 built one for music generation. Images
  need the same, against the asset ledger rather than `music_generations`.
- **No screen or route for artwork generation.** A button that spends money
  needs its own confirmation surface and its own authorization ticket.
- **`DeliveryDetailQuery::reason()` truncates but does not redact.** The same
  class of exposure GEN-003 closed for generation still exists in the
  distribution module.

`docs/project-status.json` was corrected during this session — a `main_sha` of
`25565b4`, a test count of `1002 / 4282`, and a `default_branch` of `main` when
GitHub's default is still the verifier branch — and is kept current per ticket.
Its four standing `next_action` items (ADR-0015, per-screen accessibility on
real assistive technology, AUDIT-002's actor identity for `src/Api`, and
real-host certification) are untouched and still open; the Phase 2 order was
inserted ahead of them, not in place of them.

`docs/production-readiness.md` now covers all six modules built this session —
transcription, AI enrichment, editorial, production planning, artwork and the
generation hardening — under the same four-value vocabulary. One row is
deliberately **NOT_READY**: image generation on the shipped configuration, for
the reason ART-002 gives. No existing verdict elsewhere was upgraded.

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
