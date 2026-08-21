# SaniTube — Final Status Report

**Date** 2026-08-21 · **Branch** `main` · **Suite** 2152 PHP tests (27 811 assertions), 28 component tests · **CI** 10 checks, green

This is the report §83 asks for: every field named, with the verdict the
platform can actually defend. It is derived from `docs/production-readiness.md`
rather than written beside it — where the two would disagree, the ledger is the
one under test (`ProductionReadinessLedgerTest`), and this document is the
summary.

---

## 1. What SaniTube is

| Field | Status |
|---|---|
| Purpose | A music operations and automation platform for a label managing its own catalogue |
| Not | An accounting platform, a royalty system, a revenue-sharing system, a financial ledger |
| Money | Never handled. Earnings stay with the distributor. Rights *metadata* is in scope and is READY |
| Primary target | A shared cPanel account: no shell, no Docker, no Redis, no root |
| Also supported | Ubuntu VPS with local processing; Core-only with manual operation |
| Shape | Laravel 12 modular monolith, 28 modules under `src/`, 785 PHP files |
| Interface | Vue 3 + TypeScript strict + Inertia 2 + Tailwind 4, 37 screens, 25 shared components |
| Languages | 6 — en, fr, es, it, pt, de |

## 2. Verdict counts

| Verdict | Count | Meaning |
|---|---|---|
| READY | 176 | Built, tested, exercised end to end inside the platform |
| BLOCKED_EXTERNAL | 10 | Complete on our side; needs a credential, a real provider, or a real host |
| NOT_READY | 6 | Internal work remains, each one named |
| NOT_REQUIRED | 3 | Deliberately out of scope for V1 |

**BLOCKED_EXTERNAL is not NOT_READY**, and the distinction is the point. A
fake-provider integration that is fully wired, tested and safe is finished code
awaiting a certificate, not unfinished code.

## 3. Engineering discipline

| Field | Status |
|---|---|
| CI checks | 10 — PHP 8.2/8.3/8.4 × SQLite/MySQL 8/MariaDB 10.6/11.4, static analysis, style, frontend |
| Static analysis | PHPStan level 6, **no baseline**, zero `@phpstan-ignore` in `src/` or `app/` |
| Style | Pint, enforced |
| Frontend | `vue-tsc` strict, Vitest, production build, plus a render against the built manifest |
| Migrations | 48, each rolled back and re-applied in CI |
| ADRs | 20 |
| Operator documentation | 132 files |
| Mutation testing | **By hand, per ticket, not in CI.** There is no mutation harness; each ticket's guards were verified by breaking them deliberately and confirming a named test failed. Where a mutant survived because it was equivalent, that is recorded rather than counted as a kill — see the `hashed` cast in E2E-003. |

## 4. The seven things that are not done

Each is internal work, and each is named rather than hand-waved.

| # | Field | Why it is open |
|---|---|---|
| 1 | Artwork generation on the shipped configuration | Deliberate. The default 3000px requirement and GPT-image's only square size (1024) genuinely disagree, so generation declines out of the box rather than producing something too small. An operator resolves it by lowering the requirement or declaring a larger size. |
| 2 | Colour-profile inspection | `getimagesize` cannot read an ICC profile, so "is this sRGB" is unanswered rather than guessed. Needs an image library this platform deliberately avoids. |
| 3 | Backups encrypted at rest | They are not. The destination is to be treated as the database is; off-machine copies and their encryption are the operator's. |
| 4 | A bare hostname is not scrubbed from a failure message | Deliberate. The rule is anchored on `scheme://`, because a heuristic loose enough to catch `distributor.example port 443` catches every dotted word in every message. |
| 5 | Five index screens the scale test cannot reach | Each needs a chain of domain objects the catalogue fixture does not build. All five were read: one had a real N+1, now fixed and held where its objects live; the other four are eager-loaded. |
| 6 | Keyboard navigation and tab order per screen | The primitives behave. Nothing checks that a given screen's controls come in a sensible order. 37 screens, and a scan cannot answer it. |
| 7 | Screen-reader semantics beyond labelling | Landmarks, reading order and live regions are unaudited. A scan can hold "every control has a name"; it cannot hold "this page makes sense read aloud". |

## 5. The ten things awaiting the outside world

Nothing here is unfinished code. Each needs a credential, a live endpoint, or a
real host, and **none of it has ever been executed against the real thing** —
which is exactly what these rows say.

| Field | What would close it |
|---|---|
| Real object-storage certification | R2/S3/B2 credentials. `sanitube:storage:check --certify` covers write, read, checksum, move, signed read, presigned write, delete. CORS remains browser-only. |
| Certified against the real OpenAI API | A key. |
| Certified against real vendor endpoints (AI) | An OpenAI or Anthropic key. |
| Certified against a real image endpoint | A key. |
| Real third-party generation provider | **Not credentials.** Suno publishes no API contract at all; ADR-0018 records the four conditions that would unblock an adapter and permanently excludes reverse-engineered wrappers, enforced in CI. |
| Certified against a real GPU worker | A running ACE-Step. Contract-checked against published source, never executed. |
| Certified against a real remote worker | One, plus `sanitube:worker:check` to run against it (WRK-003). The handshake, refusals and containment are tested against a faked transport. |
| Real distributor (Too Lost / TuneCore) | Their API, which is not invented here. |
| Certified on a real cPanel/VPS host | Somebody running it on one. Never claimed as tested. |
| Full external penetration test | An external tester. Internal audit only. |

## 6. Deliberately out of scope

| Field | Status |
|---|---|
| Royalties, revenue, payouts, ledger, accounting | Out of scope for V1 by design |
| Unattended release | Deliberately unavailable — the lock is the feature |
| Re-import storage amplification | Measured at factor 2 and accepted for V1 (ADR-0015). Every option that removes the second copy either breaks the UUID-derived object key or loses the record that a second import happened. |

## 7. What the platform refuses to do

The refusals are load-bearing, and each is tested.

| Field | Status |
|---|---|
| Invent an ISRC, UPC or EAN | Never. An unassigned identifier is an empty cell and a warning. |
| Present a revoked identifier as active | Never. Scoped at the query boundary. |
| Let a worker decide catalogue identity | Never. Not duplicate deletion, track promotion, release membership, or distribution eligibility. |
| Scrape an unofficial web UI, or depend on a reverse-engineered API | Never. Enforced in CI. |
| Serve a permanent public URL for an asset | The contract has no such method; no disk declares `url`. |
| Grant readiness without earning it | Three states, three granting methods, each after a check — and a scan holds that nothing else writes them. |
| Put a secret or an address in a message a person reads | One rule, `CredentialRedactor::scrub()`, at 13 call sites across 9 files. Six boundaries carry text to a person; four of them are durable columns, scrubbed on the way in *and* on the way out, because rows written before the rule are already in the column. |
| Put `.env` in a backup | Never, whatever the configuration says — and a backup naming one is refused on restore. |
| Restore without verifying | Manifest, containment, completeness, checksums, schema drift, explicit confirmation — in that order, before anything is written. |
| Prune an incomplete backup, or the newest one | Never, whatever `keep` says. |
| Permanently delete anything | **Not implemented at all**, and not an oversight. It would need a minimum age, a dry run, a confirmation naming what is about to go, and a guarantee that the object addressed is the one that was reviewed. A duplicate is marked, never removed. |
| Treat a filename as identity | Identity is the asset's uuid and its checksum; an ingestion is keyed on `ingestion_key` behind a unique index. The original filename is carried as a label and as a readable suffix on the object key, and nothing looks anything up by it. |

## 8. Three installations, one codebase

No source change between them; each is a legitimate configuration.

| Installation | Storage | Processing | Notes |
|---|---|---|---|
| Shared cPanel | R2 or S3-compatible | GPU worker over HTTP | The primary target. No shell assumed anywhere. |
| Ubuntu VPS | Local disk or object storage | Local FFmpeg, local worker | Scheduler via cron. |
| Core-only | Local disk | None | Manual operation; every capability that is absent is reported, not fatal. |

## 9. What this session changed

| Ticket | What it was |
|---|---|
| SEC-002 | Every route guarded, or named with a reason. Read from the live router. |
| DEP-005 | A backup contains what it was told to and nothing outside the application; `.env` never; and pruning could not delete a backup containing a hidden file. |
| DEP-006 | The doctor says whether the *next* backup can run, not only when the last one was. |
| OBS-001 | A failure message carries no secret and no address — six boundaries, one rule. |
| E2E-003 | The owner the installer creates can actually sign in. Neither suite held that joint. |
| PERF-003 | A payload comparison was measuring its own fixture; the tolerance came *down* from 1024 to 256. |
| PERF-004 | The enrichment queue cost 2 queries per row; it now costs the same for one row as for a page. |
| PERF-005 | The five screens the scale test could not reach are measured, and the list of screens is now read from the router instead of kept by hand. |
| CAT-003 | Nothing but the three granting methods may write an earned state. |
| UI-A11Y-001 | Three raw inputs were orphaning their labels on the enrichment review screen; two file inputs sat unnamed in the accessibility tree. |
| DOC-001 / DOC-002 | This report, with its counts held by a test; and the machine-readable status file, which was 20 tickets behind while carrying today's date. |
| OBS-002 / OBS-003 | The doctor reports a queue nobody is working, and work that failed and nobody has looked at. Neither can see the other’s failure. |
| OBS-004 | Degraded capabilities reach the doctor instead of only `sanitube:health`. |
| WRK-003 | A command to certify a real worker: handshake, capabilities, refusals, token. The storage side had one; the worker did not. |
| DIST-008 | The work crosses with the recording: ISWC and writer credits reach the distributor. ADR-0021. |
| DIST-007 | A distributor receives the `ReleasePackage`, never the aggregate. ADR-0020. It immediately caught a release with a track that had no master audio being submitted by the suite's own fixture. |

## 10. The decision that was waiting, and what it changed

**`Distributor::submitRelease()` took the `Release` aggregate rather than
`ReleasePackage`.** Approved and done in DIST-007; `ADR-0020` records it.

All three release-taking methods now take the package. `validateRelease()`
changed with the other two deliberately — leaving it would have reopened the
hole one method along, and *would you accept this?* is only meaningful about the
thing that would actually be sent.

It found something on its first run. `DistributionTest`'s fixture built a track
by writing `TrackStatus::Ready` directly, with no master audio — the shape
`TrackFactory`'s own comment calls impossible — and the suite had been
submitting it for as long as that fixture existed. The manual export path
already refused such a release; the submission path did not check, because the
adapter was handed the aggregate and never looked. The two paths now agree.

Nothing is waiting on a decision. What remains is in sections 4 and 5: seven
things named as internal work, and ten that need a credential, a live endpoint
or a real host.
