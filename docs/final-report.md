# SaniTube — Final Status Report

**Date** 2026-08-22 · **Branch** `main` · **Suite** 2357 PHP tests (29 795 assertions, 1 skipped), 40 component tests · **CI** 13 checks, green

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
| Shape | Laravel 12 modular monolith, 28 modules under `src/`, 834 PHP files |
| Interface | Vue 3 + TypeScript strict + Inertia 2 + Tailwind 4, 37 screens, 27 shared components |
| Languages | 6 — en, fr, es, it, pt, de |

## 2. Verdict counts

| Verdict | Count | Meaning |
|---|---|---|
| READY | 227 | Built, tested, exercised end to end inside the platform |
| BLOCKED_EXTERNAL | 10 | Complete on our side; needs a credential, a real provider, or a real host |
| NOT_READY | 6 | Internal work remains, each one named |
| NOT_REQUIRED | 3 | Deliberately out of scope for V1 |

**BLOCKED_EXTERNAL is not NOT_READY**, and the distinction is the point. A
fake-provider integration that is fully wired, tested and safe is finished code
awaiting a certificate, not unfinished code.

## 3. Engineering discipline

| Field | Status |
|---|---|
| CI checks | 13 — PHP 8.2/8.3/8.4, four database engines (SQLite, MySQL 8, MariaDB 10.6, MariaDB 11.4), static analysis, style, frontend, and the bootstrap run in a container on Ubuntu 24.04, Debian 12 and AlmaLinux 9 |
| Static analysis | PHPStan level 6, **no baseline**, zero `@phpstan-ignore` in `src/` or `app/` |
| Style | Pint, enforced |
| Frontend | `vue-tsc` strict, Vitest, production build, plus a render against the built manifest |
| Migrations | 51, each rolled back and re-applied in CI on every engine |
| ADRs | 23 |
| Operator documentation | 140 files |
| Mutation testing | **By hand, per ticket, not in CI.** There is no mutation harness; each ticket's guards were verified by breaking them deliberately and confirming a named test failed. Where a mutant survived because it was equivalent, that is recorded rather than counted as a kill — see the `hashed` cast in E2E-003. |

## 4. The six things that are not done

Each is internal work, and each is named rather than hand-waved. **Only one of
the six is work somebody could simply sit down and do** — the other five are a
stated configuration decision, a dependency this platform declines to take, a
deliberate scrubbing rule, and two audits a scan cannot perform.

| # | Field | Why it is open |
|---|---|---|
| 1 | Artwork generation on the shipped configuration | Deliberate. The default 3000px requirement and GPT-image's only square size (1024) genuinely disagree, so generation declines out of the box rather than producing something too small. An operator resolves it by lowering the requirement or declaring a larger size. |
| 2 | Colour-profile inspection | `getimagesize` cannot read an ICC profile, so "is this sRGB" is unanswered rather than guessed. Needs an image library this platform deliberately avoids. |
| 3 | Backups encrypted at rest | They are not. The destination is to be treated as the database is; off-machine copies and their encryption are the operator's. **The one item here that is internally actionable, and it is held for a decision rather than left undone**: encryption changes what a restore requires and makes a lost key an unrecoverable backup, which is destructive storage semantics and so a REVIEW_REQUIRED area. |
| 4 | A bare hostname is not scrubbed from a failure message | Deliberate. The rule is anchored on `scheme://`, because a heuristic loose enough to catch `distributor.example port 443` catches every dotted word in every message. |
| 5 | Keyboard navigation and tab order per screen | The primitives behave. Nothing checks that a given screen's controls come in a sensible order. 37 screens, and a scan cannot answer it. |
| 6 | Screen-reader semantics beyond labelling | Landmarks, reading order and live regions are unaudited. A scan can hold "every control has a name"; it cannot hold "this page makes sense read aloud". |

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

What remains is in sections 4 and 5: six things named as internal work, and ten
that need a credential, a live endpoint or a real host.

## 11. Where the internal work stops

**Internal Phase 2 is complete**, and that is a claim worth stating precisely
rather than warmly. It does not mean the platform is finished. It means every
remaining item needs something this environment does not have — an external
credential, access to an external API, a real GPU worker, a certified R2
bucket, a real distributor account, or a person at a keyboard with a screen
reader — or it needs a decision that is the owner's to make.

There is exactly one of the latter, and section 4 names it: **encrypting
backups at rest**. It is buildable today. It is not built because it changes
what a restore requires and turns a lost key into an unrecoverable backup, and
destructive storage semantics is a REVIEW_REQUIRED area. Doing it quietly would
have been the wrong kind of progress.

Four priorities were audited and produced no work, which is reported rather
than converted into tickets: the transcription and enrichment production paths
(every service has a real caller, both listeners are registered), artwork
generation, the production plan end to end (its unscheduled cadence is
deliberate and documented), and release preparation. Three more — distributor
research, DDEX, and a real adapter — are shut by network policy, and writing
them up from memory would have meant inventing external facts.

The machine-readable form of this section is `internal_phase2_complete` in
`docs/project-status.json`.

## 12. The deployment automation mission (DEP-007 → DEP-018)

START_MAIN_SHA `e10b01c` → END_MAIN_SHA recorded in `docs/project-status.json`
(`main_sha`). Twelve tickets, nine pull requests (#137–#145), every one merged
at full green — including three new CI jobs that run the bootstrap on real
Ubuntu 24.04, Debian 12 and AlmaLinux 9 containers.

**What a fresh VPS now takes:**

```
git clone … && cd … && bin/bootstrap.sh --yes-install-packages
php artisan sanitube:install --profile=VPS_CORE --config=/root/sanitube-install.conf
php artisan sanitube:provision … --into=… && (root installs what was generated)
php artisan sanitube:frontend:install <artifact> --sha=<commit>
php artisan sanitube:self-test
```

**Statuses, in the mission's vocabulary:**

| Field | Status |
|---|---|
| Installer profiles / host detection / journal / resume / dry-run / non-interactive | DONE — DEP-007/008/009 |
| cPanel automation | DONE to the platform's edge: web installer, cron line, prebuilt frontend; document root and AutoSSL stay cPanel's own controls |
| VPS automation (nginx, systemd queue template, scheduler timer) | DONE — generated, validated at the door, installed by root |
| HTTPS | Delegated to `certbot --nginx` on a generated HTTP block, deliberately — certificate paths belong to the ACME client |
| Frontend artifact / deploy artifact | Frontend DONE (atomic, SHA-tied, hostile-archive-safe). A full source+vendor deploy package: evaluated, not built — git ref + artifact covers all three installation shapes without a second distribution channel to audit |
| Update / rollback / deploy lock / maintenance | DONE — `bin/update.sh <ref>` (no default ref), file lock with stale takeover, backup before the curtain, doctor+smoke after `up`. Rollback = previous ref; schema stays forward-fix |
| Backup / restore | Scheduled nightly (`SANITUBE_BACKUP_AT`), freshness judged separately; restore rehearsal is certification step F |
| R2 configuration / certification / migration | Config + real certification command + ledger DONE; `sanitube:assets:relocate` (ADR-0022) moves the catalogue under proof; the real bucket run is certification step G; browser CORS needs a browser |
| Worker | Token minting, certification, protocol version with refuse-on-mismatch — DONE; real host is step H |
| ACE-Step / GPU detection | BLOCKED_EXTERNAL / deferred to a machine with a GPU (step J) — nothing invented |
| OpenAI / Anthropic / image / distributor / DDEX | Standings in `sanitube:providers`; real calls are operator-triggered, DDEX stays BLOCKED_EXTERNAL |
| Installer security | Input refusal (units/nginx/domains), archive traversal guards, 0600 config enforcement, script hygiene as a discovered-not-listed guardrail, checksum-verified bootstrap download |
| Portability | No domain, path, account, bucket, port or cadence in source; distro assumptions CI-tested; the dash-vs-bash bug the distro job caught on its first run is the proof it earns its keep |

**INTERNAL_DEPLOYMENT_AUTOMATION_COMPLETE: YES** — with the same precision as
section 11: every remaining line needs a real host, a real credential, or a
human decision. **READY_FOR_REAL_VPS_CERTIFICATION: YES** —
`docs/deployment/certification-plan.md` is the script, A through K, runnable
with shipped code.

**Defects found by this mission's own guardrails, fixed in flight:** the test
classes that ran the real installer without wrapping (leaked owners on every
file-backed database in the matrix); the variable-width faker titles the scale
test sorted on (264 bytes of growth against a 256 tolerance, one CI run in
five); Composer-as-root refusing plugins on the exact host shape the bootstrap
exists for; dash rejecting `pipefail` on Debian-family containers; a lingering
relocation sanction that a refused save failed to spend; and a docblock naming
a storage vendor inside the Assets domain, caught by the boundary test.

**Recommended first reference VPS:** Ubuntu LTS or Debian stable, 2 vCPU,
2 GB RAM, 40 GB disk, public IP, DNS pointing. GPU worker sized separately,
per provider.

**Exact next operator actions:** run the certification plan, A first; every
manual intervention it surfaces comes back as a ticket.

---

## 13. Configuration centre, storage and provider administration

The section this mission asks for. Every line is a fact about shipped code, and
the three that are not YES say why.

### What an operator can now do without a shell

| Field | Status |
|---|---|
| CONFIGURATION_CENTER | **DONE** — twelve sections, each its own card: storage, AI, music generation, distribution, workers, audio/media, email, queue/scheduler, backup, production automation, system/health, API. Not one giant form. The application's own name, environment, debug flag, locale and config-cache state are a separate read-only block above them — debug and `APP_KEY` are deployment decisions and are deliberately not fields |
| SETTINGS_ARCHITECTURE | **DONE** — bootstrap config stays in `.env`, runtime settings are written through an allow-list, secrets are separate. Nothing queries the database inside a Laravel config file, nothing calls `env()` at runtime, and every write rebuilds the config cache or restores the previous `.env` |
| SECRET_HANDLING | **DONE** — encrypted at rest by the platform's own `.env` handling, never returned to the browser after save, never logged, never in an audit payload, never in an exception. The screen shows *configured* or *not configured* — no mask, no length, no last four |
| BLANK_NEVER_ERASES | **DONE** — a blank field means unchanged, for secrets and plain settings alike. Removing a value stays a `.env` edit, deliberately, so that saving a rate limit cannot silently unset a provider key |
| PROVIDER_SELECTION | **DONE** — storage, AI, generation and distribution are all chosen from the screen, each constrained to the same list the screen offers, both read from one declaration |
| CONNECTION_TEST | **DONE** — `POST /settings/test` runs the *same* probes the deploy-gate commands run, records a real certification on a pass, and answers with a closed vocabulary. Never an address the caller composes |
| MAIL_TEST | **DONE** — one real message, to the signed-in operator's own address and nowhere they choose |
| CERTIFICATION_VISIBLE | **DONE** — the six-state standings are on the settings screen, identical to what `sanitube:providers` reports, asserted by test |
| SPENDING_CONTROLS | **DONE** — rolling quotas and circuit breakers for AI and generation, both enforced on every call and previously configurable only over SSH |
| GLOBAL_STOP | **DONE** — visible on the operations screen and pressable from it |

### Storage and R2

| Field | Status |
|---|---|
| R2_CODE_READY | **YES** — provider, declared credential fields, real certification round trip (`PUT → HEAD → READ → verify → DELETE`, no permanent artifact), ledger record, and `sanitube:assets:relocate` to move an existing catalogue under proof (ADR-0022) |
| R2_CONFIGURED | **NO** — no credentials exist in this environment |
| R2_CONNECTIVITY_VERIFIED | **NO** — and it cannot be, without a bucket. The moment credentials are entered, "Test connection" answers |
| STORAGE_SWITCHING | **DONE** — the provider is a writable setting; the screen then asks for that provider's own variables, and `sanitube:assets:relocate` moves what is already stored |
| FILENAME_IS_NOT_IDENTITY | **DONE** — an object's key is minted, never derived from what a file was called |

### The real upload failure

| Field | Status |
|---|---|
| UPLOAD_BUG_DIAGNOSED | **YES** — PHP empties `$_POST` and `$_FILES` when a body exceeds `post_max_size`, so a real 4.7 MB MP3 arrived looking identical to no file at all and was told the file field was required |
| UPLOAD_BUG_FIXED | **YES** — UPL-004. The import screen now knows the host's real ceiling and refuses oversize files before sending; a discarded body answers `HOST_UPLOAD_LIMIT` at 413 with the number; the doctor reports the disagreement before a person meets it |
| REGRESSION_TEST | **YES** — six tests, including one that builds a genuine MP3 rather than a text file with an extension |

### What is deliberately not done

| Field | Status | Why |
|---|---|---|
| TRASH_PURGE | **NOT BUILT** | Permanent deletion is destructive and belongs to a person, not to an autonomous session. TRASH-001 made it *safer* to build — a purge can no longer be handed an object a delivery package still references — and the decision about minimum age, dry-run and package-reference blocking is the operator's |
| BACKUP_ENCRYPTION_AT_REST | **REVIEW_REQUIRED** | Stated in `docs/deployment/backup.md`. Not added silently: encryption makes a lost key an unrecoverable backup, which is a trade nobody should make on somebody else's behalf |
| DUPLICATE_BULK_ACTIONS | **NOT BUILT** | The affordance most likely to discard a legitimate second copy. `DuplicateDecision` argues that even byte-identical findings must start as *proposed* for exactly that reason, and weakening it is a product decision |
| SCHEDULED_DUPLICATE_SWEEP | **NOT BUILT** | `sanitube:duplicates:evaluate` exists with a `--limit` and is in no schedule. Adding it changes production runtime cost, which on a shared cPanel account is not a free change |

### Production

| Field | Status |
|---|---|
| DEPLOYED_TO_PRODUCTION | **NO** — this environment has no access to the host. `bin/update.sh <ref>` is the tooling, it backs the database up before migrations, and it does not reinstall the server |
| PRODUCTION_SMOKE_RUN | **NO** — `sanitube:smoke` exists and is CI-tested; it needs the real URL |
| SANITUBE_PRODUCTION_READY | **YES for the code, NO as a running deployment** — every gate green on thirteen checks across three PHP versions and four database engines, with the bootstrap exercised in containers on three distributions. What remains is not code: a host to install on, credentials to certify against, and four decisions that are the operator's to make |

