# SaniTube — Production Readiness

Every control, with an honest verdict. Four values and no fifth:

| | |
|---|---|
| **READY** | Built, tested, and exercised end to end inside the platform. |
| **NOT_READY** | Internal work remains. Named, not hand-waved. |
| **BLOCKED_EXTERNAL** | Complete on our side; needs credentials, a real provider, or a real host to certify. |
| **NOT_REQUIRED** | Deliberately out of scope for V1. |

**BLOCKED_EXTERNAL is not NOT_READY.** The distinction is the point of this
document: a fake-provider integration that is fully wired, tested and safe is
finished code awaiting a certificate, not unfinished code. Saying otherwise
would misdescribe the work in both directions.

`php artisan sanitube:doctor` checks the machine-checkable subset of this list
on a live installation, read-only, and exits non-zero when something internal
blocks going live.

**Last audited against the code: 2026-08-21 (READY-001).** A ledger is only
worth what its last reading was. This one had drifted in the *pessimistic*
direction — four controls were marked NOT_READY that had since been built, and
an entire subsystem had no row at all — which is a less dangerous failure than
the opposite and just as corrosive: a document nobody can trust in one
direction is a document nobody trusts in either. Each verdict below was
re-checked against a route, a command, a class or a test, and the ones that had
moved were moved.

`ProductionReadinessLedgerTest` keeps the mechanical half honest from here:
every `sanitube:*` command and every ADR this page names must exist. It cannot
tell that a row is *stale* — nothing links a sentence to the feature it
describes — so the audit above stays a thing somebody does, and its date stays
written down.

---

## App

| Control | Verdict | Notes |
|---|---|---|
| Application key set | READY | `sanitube:doctor` blocks without one. |
| `APP_DEBUG` off in production | READY | Blocker in production, warning elsewhere. |
| `APP_URL` set and https in production | READY | Signed URLs and emailed links build from it. |
| Six-language interface | READY | `en fr es it pt de`, gated both directions. |
| Error pages do not leak internals | READY | Analyser failure messages and storage paths are stripped at the query boundary. |

## Auth and identity

| Control | Verdict | Notes |
|---|---|---|
| Session auth, first OWNER at install | READY | |
| OWNER / ADMIN / MEMBER, MEMBER read-only | READY | Guards on routes, asserted by posting past hidden buttons. |
| Deactivation rather than deletion | READY | `active` middleware drops access at the next request. |
| No self-registration | READY | |
| No mass assignment of `role` / `is_active` | READY | |
| Password reset flow | READY | Built. The request form answers identically for a known and an unknown address; a deactivated account is sent nothing and told nothing; a token is single-use, expiring, and refused after a deactivation; a reset ends the sessions opened with the old password. |

## Database

| Control | Verdict | Notes |
|---|---|---|
| SQLite / MySQL 8 / MariaDB 10.6 / 11.4 | READY | Four-engine CI matrix on every PR. |
| Portable schema — no `json()`, no DB enums | READY | Enforced by a migration-scanning test. |
| Additive, rollback-safe migrations | READY | Matrix migrates, tests, rolls back, migrates again. |
| UUIDs exposed, never auto-increment ids | READY | |
| Pending-migration detection | READY | `sanitube:doctor`. |

## Storage

| Control | Verdict | Notes |
|---|---|---|
| No disk, bucket, key or path in any payload | READY | Asserted per screen. |
| Previews minted by POST, signed, short expiry | READY | Throttled; audited without the URL. |
| Backup destination outside the web root | READY | Refused at write time *and* by the doctor. |
| Path traversal refused on read and write | READY | Manifest entries are data, checked before any filesystem call. |
| Direct browser upload | READY | STO-003. The asset is registered before the URL exists, so the key the URL can write to was chosen here; the completion signal carries no size, checksum or media type — all of it is measured from the stored object. |
| Real object-storage certification | BLOCKED_EXTERNAL | STO-002 opt-in suite, and STO-006's `sanitube:storage:check --certify` — no credentials in CI, and nothing here has spoken to R2. The bucket's CORS rule is provable only from a browser. |

## Queue and scheduler

| Control | Verdict | Notes |
|---|---|---|
| Import and analysis run on the queue | READY | Asserted as *pushed*, never run inline. |
| `sync` refused for production | READY | `sanitube:doctor` blocker. |
| Scheduler heartbeat, staleness visible | READY | Never-run is never reported as healthy. |
| Failed-job visibility | READY | Jobs screen. |
| Failed-job retry / delete from the interface | READY | SYS-001b. Behind `can.role:administer`; addressed by the failed job's uuid, never by the `failed_jobs` row id. Which jobs may be re-run is `ResolveFailedJob`'s decision, asked of the job's own type. |

## Media

| Control | Verdict | Notes |
|---|---|---|
| Analysis optional; absent FFmpeg does not block | READY | The shared-hosting default produces READY, not WAITING_CAPABILITY. |
| Checksums verified on ingest | READY | |
| Duration / loudness when available | READY | |
| Where media work runs is a setting, not a branch in the domain | READY | WRK-002. `local` / `remote_worker` / `auto`; an unreadable value is `auto` rather than a boot failure. No caller knows which machine ran the binary — a test asserts `AnalyzeAsset` and `FingerprintAsset` mention no worker type at all. |
| Availability is never inferred from configuration | READY | WRK-002. A URL and a token prove somebody typed two settings. The worker is asked, and `REMOTE_WORKER_UNAVAILABLE_LOCAL_AVAILABLE` is *degraded*, not green: real work is happening on the machine the operator did not choose. |
| Masters do not transit Core to reach a worker | READY | WRK-002. The worker is handed an object key and reads the shared store itself. Pulling a master down so a worker can push it back up would be twice the egress — on a cPanel account facing R2, twice the bill. |
| A library stored before the tool existed can be fingerprinted | READY | MED-004. `sanitube:media:fingerprint`; the doctor warns while any master is uncovered. |

## Transcription

| Control | Verdict | Notes |
|---|---|---|
| Optional by design; absent provider is not a failure | READY | A library with no supplier configured is complete, not broken. |
| Provider certification is a state, never a config claim | READY | TRN-002. `CONFIGURED_UNCERTIFIED` until a real call proves otherwise; config alone can never say `CERTIFIED`. |
| OpenAI adapter written against the published API contract | READY | `verbose_json`, seconds converted to milliseconds, size guarded before the request. |
| Vendor error bodies never reach a screen | READY | Status code kept, body dropped; transport errors redacted. |
| Provider is handed a local path, never a URL | READY | No adapter can be talked into fetching an arbitrary address. |
| Transcription reachable from the interface and the queue | READY | TRN-003. Route, job, listener and backlog command; automatic mode off by default. |
| Idempotent per provider version | READY | Re-running the same version returns the stored row rather than paying again. |
| **Certified against the real OpenAI API** | BLOCKED_EXTERNAL | No key in CI. The adapter has never spoken to the live endpoint. |

## AI enrichment

| Control | Verdict | Notes |
|---|---|---|
| Structured output required, never prose parsed | READY | ENR-001. `json_schema`+`strict` at OpenAI, a forced tool at Anthropic. |
| Malformed model output is a refusal, not partial data | READY | The shape is enforced before anything is stored; model text is not carried into the failure. |
| Prompt-injection boundary | READY | The instruction is a fixed constant; catalogue text only ever arrives as input. |
| Suggestions are suggestions | READY | ENR-002. A `MetadataSuggestion` row; nothing is written to a Track by a model. |
| Call ceiling and circuit breaker | READY | ENR-003. Rolling 24h/168h/720h windows over the invocation ledger; per-provider cooldown. Counts calls — no prices, no currency, no balance. |
| Accept / reject, audited, one decision only | READY | ENR-004. Guarded `UPDATE` inside a transaction; released tracks refuse edits. |
| Four levels of truth kept apart on screen | READY | ENR-005. `canonical` / `measured` / `proposed` / `suggested` as four server-named objects. |
| **Certified against real vendor endpoints** | BLOCKED_EXTERNAL | No OpenAI or Anthropic key in CI. |

## Editorial and production planning

| Control | Verdict | Notes |
|---|---|---|
| Editorial profile per imprint | READY | EDI-001. Guidance that a prompt can carry; refuses a half-made profile. |
| Autonomy modes are an enum, not a boolean | READY | PROD-001. Manual / Assisted / AutonomousGeneration / AutonomousPreparation. |
| `AUTONOMOUS_RELEASE` locked | READY | Unavailable in code, not by configuration. Three independent tests hold it shut. |
| Plan status set by the platform is distinguishable | READY | `wasSetByThePlatform()`; a paused plan and an exhausted one are not the same thing. |
| Slots opened once, claimed once | READY | PROD-002. Unique index on (plan, occasion); a guarded claim; a lost race is a success, not an error. |
| Nothing is generated blindly | READY | PROD-003. Inventory counted before work; no attribution is a refusal, not a default. |
| An opened slot becomes real work | READY | PROD-004. The occasion a plan opened becomes a generation request through one bridge; the read is a suggestion and the guarded write is the decision, so two workers racing produce one request. |
| A crashed worker cannot strand a slot | READY | PROD-005. Claims expire and are reaped; a claim recovered is a slot returned to the queue, not a slot lost. |
| Unattended release | NOT_REQUIRED | Deliberately unavailable. The lock is the feature. |

## Artwork

| Control | Verdict | Notes |
|---|---|---|
| A cover is judged on what was measured | READY | ART-001. Dimensions, format and channel count read from the file, stored per measurer version, and used by the release validator. |
| Unmeasured is a warning, never a pass | READY | An installation that cannot measure images reports "nobody has looked" rather than conforming — and rather than reporting every release as broken. |
| Unreadable is an error, not a warning | READY | Somebody looked and it is not an image. Distinct from nobody having looked. |
| The minimum applies to the shortest side | READY | A 4000×1000 image satisfies "at least 3000 wide" and is not a cover. |
| No distributor named in the requirements | READY | Every rule is configurable; zero means no requirement. Stores disagree at the edges, so an operator changes a number rather than patching a validator. |
| Screens show measured dimensions only | READY | ART-001 found `assets.width`/`height` were columns nothing writes, read by three screens and masked by a test fixture. All three now report null until something has measured. |
| Backfill for covers verified earlier | READY | `sanitube:artwork:measure`, bounded, and it says what it left behind. |
| Image generation refuses before spending | READY | ART-002. Feasibility is checked against the provider's declared sizes before a request leaves; an unreachable requirement is `REQUIREMENTS_UNREACHABLE` and nothing is sent. |
| A generated cover is an ordinary asset | READY | Registered, stored, checksummed, verified and measured like any upload. The provider's claim about what it produced is never what the platform reports. |
| Generation usable on the shipped configuration | **NOT_READY** | Deliberate and stated in `config/artwork.php`: the default 3000px requirement and the specification's only square GPT-image size (1024) genuinely disagree, so generation declines out of the box. An operator resolves it by lowering the requirement or declaring a larger size their account supports. |
| No colour-profile inspection | NOT_READY | `getimagesize` cannot read an ICC profile, so "is this sRGB" is unanswered rather than guessed. Needs an image library this platform deliberately avoids. |
| **Certified against a real image endpoint** | BLOCKED_EXTERNAL | No key in CI. The adapter has never spoken to OpenAI. |

## Catalogue

| Control | Verdict | Notes |
|---|---|---|
| Tracks, artists, contributors, compositions | READY | |
| Track credits from the interface | READY | CAT-002. |
| Readiness earned, never assigned | READY | I3 re-run on every attempt. |
| ISRC / UPC / EAN never invented | READY | Assigned deliberately; format-normalised. |
| Revoked identifiers never presented as active | READY | Scoped at the query boundary. |

## Import

| Control | Verdict | Notes |
|---|---|---|
| Bulk import from cloud storage | READY | 900-file scale is a queueing property, asserted where it belongs. |
| A catalogue larger than one batch | READY | BULK-003. `sanitube:import --continue` takes the next batch; what is done is read from the items table. CLI only — the browser still refuses an over-cap selection. |
| CSV manifest import | READY | |
| Start an import from the interface | READY | ING-002. |
| Import never creates a Track | READY | The load-bearing negative claim. |
| Re-import storage amplification | NOT_REQUIRED | BULK-001c measured it: factor 2, asserted with the number. ADR-0015 accepts it for V1 and reclassifies the optimisation `POST_V1_STORAGE_OPTIMIZATION` — every option that removes the second copy either breaks the UUID-derived object key or loses the record that a second import happened, and trading an integrity invariant for disk is the wrong direction. |

## Generation

| Control | Verdict | Notes |
|---|---|---|
| Studio, projects, generations | READY | |
| Fake provider drives a full E2E | READY | |
| Generated audio joins the review queue | READY | Never becomes a Track directly. |
| Submitted once, even by two workers | READY | GEN-003. A guarded `UPDATE` claim; the claim expires so a crashed worker cannot strand a generation. |
| No provider error text reaches a browser | READY | GEN-003. A client exception quotes the request; on a query-string-authenticated provider that message *is* the credential. Vendor explanations are kept but redacted, address-stripped, bounded and attributed. |
| Request ceiling and circuit breaker | READY | GEN-004. Rolling 24h/168h/720h windows over `music_generations` itself; per-provider cooldown. Counts requests — no prices, no currency, no balance. |
| A provider may be synchronous | READY | ADR-0019 / GEN-007. Four execution shapes; `SubmitMusicGeneration::execute()` is the only place that branches on which. No invented job id for a provider that answers at once. |
| Self-hosted generation provider | READY | GEN-008. ACE-Step, written against its published API, run on a worker. The worker chooses checkpoint, device, output root and filename; the request cannot. `ContainedPath` resolves with `realpath()` before comparing, and the provider's `output_path` never becomes a SaniTube storage path, never reaches a browser, and never requires Core and the GPU to share a filesystem. |
| Real *third-party* generation provider | BLOCKED_EXTERNAL | GEN-002 / AI-002. GEN-005 established the blocker is not credentials: **Suno publishes no API contract at all.** ADR-0018 records the four conditions that would unblock an adapter and permanently excludes reverse-engineered wrappers, enforced in CI. |
| **Certified against a real GPU worker** | BLOCKED_EXTERNAL | The adapter has never spoken to a running ACE-Step. Contract-checked against the published source, not executed. |

## Worker

| Control | Verdict | Notes |
|---|---|---|
| One boundary, addressed by capability | READY | WRK-001. GEN-008 needed a remote host for one purpose and the temptation afterwards is a second boundary for FFmpeg, a third for Chromaprint, a fourth for transcription — four authentication schemes and four things to deploy. There is one, and adding a fifth capability changes neither the protocol nor Core's client. |
| Its own credential, never a catalogue token | READY | WRK-001. One token per purpose; an installation that is not a worker answers 404 rather than 401, because 401 confirms the endpoint exists. No token, prefix or fragment is ever logged. |
| A worker declares; Core asks | READY | WRK-001. A capability named in the vocabulary is not a promise any worker answers to it. A newer worker talking to an older Core is usable for what they share. |
| The boundary knows nothing about what it carries | READY | WRK-001. Asserted: no module import in the transport. A handler declares the fields it accepts and nothing else reaches it. |
| The worker decides nothing about the catalogue | READY | Identity, duplicate deletion, promotion, release membership and distribution eligibility stay in Core. A worker returns a measurement. |
| **Certified against a real remote worker** | BLOCKED_EXTERNAL | Never executed against one. The handshake, the refusals and the containment are tested against a faked transport. |

## Deduplication

| Control | Verdict | Notes |
|---|---|---|
| Findings, never verdicts | READY | DEDUP-001. A level and the basis it came from — identical bytes or an acoustic measurement — stored side by side, because those warrant different amounts of trust. |
| The fingerprint is kept, not a score | READY | A similarity percentage is a decision taken with today's threshold. Keeping the measurement means a recalibration can re-decide without re-reading fifty thousand masters — which is the sort of thing nobody does, so the number never gets changed. |
| Comparison is bounded before it is attempted | READY | Every fingerprint against every other is 1.25 billion pairs at fifty thousand assets. Duration is the pre-filter that makes it possible at all. |
| A person answers; nothing decides | READY | DEDUP-002 / DEDUP-003. No similarity score reaches the trash. There is no threshold, however high, that sets a master aside on its own. |
| **Nothing is ever permanently deleted** | READY | The object stays, the checksum still describes it, and coming back is three columns. Permanent deletion is not implemented and is not an oversight: it would need a minimum age, a dry run, an explicit confirmation naming what is about to go, and a guarantee that the object addressed is the one that was reviewed. Disk is the cheapest thing in this system to be wrong about. |
| Acoustic coverage is visible | READY | MED-004. The doctor says when a host that *can* fingerprint has masters it never did — the one gap in this platform that otherwise produced no error anywhere. |

## Release

| Control | Verdict | Notes |
|---|---|---|
| Single / EP / Album | READY | |
| Builder owns numbering; gaps closed | READY | |
| Structured validation, six languages | READY | REL-002; codes, never English as a contract. |
| READY refuses structural change | READY | |
| Committed releases cannot be reopened | READY | |

## Distribution

| Control | Verdict | Notes |
|---|---|---|
| Fake/sandbox delivery end to end | READY | |
| Submitted exactly once | READY | Unique index, guarded claim, stable idempotency key. |
| Outage is not a rejection | READY | Decided by code, never by message text. |
| Unknown outcome handled safely | READY | `SUBMITTED_UNCONFIRMED`; retry blocked. |
| Reconciliation via lookup capability | READY | "Cannot look" is a type, not an exception. |
| Manual resolution, actor recorded | READY | `decided_by` with a RESTRICT foreign key. |
| Hand-typed references marked as such | READY | Provenance shown on the screen. |
| Delivery without a distributor API | READY | DIST-004. `sanitube:distribution:export` builds a metadata sheet and a checked file list for a person to upload to a portal. The columns are configuration; no distributor is named in the code. Not a submission: no delivery row, nothing irreversible. |
| Nothing is invented to fill a delivery cell | READY | DIST-004. An unassigned ISRC is an empty cell and a warning, never a generated code. A revoked identifier is never exported — the one place presenting a withdrawn code would put it back into circulation. |
| Real distributor (Too Lost / TuneCore) | BLOCKED_EXTERNAL | DIST-002. Their API is not invented here. |

## Backup and restore

| Control | Verdict | Notes |
|---|---|---|
| Engine-neutral dump, no `mysqldump` | READY | JSON Lines; shared hosting has no shell. |
| Dependency-ordered so a restore replays | READY | Found by E2E-001; the alphabetical dump could not be restored. |
| Partial backup never looks complete | READY | Manifest written last. |
| Corrupt backup refused before any write | READY | |
| Schema drift refused by default | READY | |
| Restore never accidental | READY | Explicit confirmation; `--force` for scripts. |
| Catalogue *and* delivery history recovered | READY | Asserted end to end. |
| Backup freshness surfaced | READY | Doctor and Operations screen. |

## Installer

| Control | Verdict | Notes |
|---|---|---|
| CLI installer | READY | |
| Never overwrites an existing `.env` or key | READY | |
| No secret in any stage output | READY | |
| Web installer for shared hosting | READY | INS-002. Same `InstallationService`; no second implementation. A filesystem-readable token gates the write, the door closes behind it, and a refusal flashes nothing back into the session. |

## Deployment

| Control | Verdict | Notes |
|---|---|---|
| `sanitube:deploy` — migrate, cache, link, restart | READY | Creates nothing; not destructive. |
| cPanel guide | READY | Document root, cron, permissions, update, rollback. |
| VPS guide | READY | |
| Production doctor | READY | Read-only; non-zero exit on internal blockers. |
| **Certified on a real cPanel/VPS host** | BLOCKED_EXTERNAL | Never claimed as tested. Nobody has run it on one. |

## Security

| Control | Verdict | Notes |
|---|---|---|
| Authorization on routes, not controllers | READY | |
| MEMBER cannot write, proven by posting past the UI | READY | |
| No internal ids in URLs | READY | |
| CSRF on every write | READY | Laravel `web` group. |
| No secrets in logs, payloads or diagnostics | READY | Asserted, including in the doctor. |
| Preview URLs signed, expiring, throttled | READY | |
| No permanent public URL for any asset | READY | STO-005. The contract has no such method; no disk declares `url`. |
| Every storage disk declares private visibility | READY | STO-005. Asserted per configured provider. |
| Storage certified against the real service | READY | STO-006. `--certify` covers move, signed read, presigned write. CORS remains browser-only. |
| Restore path traversal refused | READY | |
| Full external penetration test | BLOCKED_EXTERNAL | Internal audit only. |

## Settings

| Control | Verdict | Notes |
|---|---|---|
| Read what is configured, without values | READY | SET-001. |
| Write configuration from the interface | READY | SET-002. Allow-list; blank never clears; config cache rebuilt or the file is restored. |
| The screen names the variables this install reads | READY | STO-004. Per provider, from `config/storage.php`; a name outside the vocabulary is dropped, not offered. |

## End to end

| Control | Verdict | Notes |
|---|---|---|
| Import → review → catalogue | READY | |
| Generation → candidate → track | READY | |
| Release build → validate → READY | READY | |
| Distribution → submit → live | READY | |
| Unknown outcome → reconcile | READY | |
| Backup → destroy → restore | READY | |
| Install → OWNER → login | NOT_READY | Covered by installer tests, not by the walk. |

## Accessibility

| Control | Verdict | Notes |
|---|---|---|
| Keyboard navigation, focus, modal trap | NOT_READY | Design-system primitives do it; not audited per screen. |
| Contrast, light / dark / system | READY | Tokens; no hardcoded colours. |
| Screen-reader semantics | NOT_READY | Not audited. |

## Localization

| Control | Verdict | Notes |
|---|---|---|
| Six languages, complete both directions | READY | Gate fails on a missing key *or* an orphaned one. |
| No English business string as a contract | READY | Codes throughout. |
| Locale from preference, then browser | READY | |

## Finance

| Control | Verdict | Notes |
|---|---|---|
| Royalties, revenue, payouts, ledger, accounting | **NOT_REQUIRED** | Out of scope for V1 by design. SaniTube does not handle money; earnings stay with the distributor. Credits and rights *metadata* needed for distribution are in scope and are READY. |
