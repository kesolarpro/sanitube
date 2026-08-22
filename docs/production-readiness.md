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

A narrative summary of this table, with the outstanding work named and grouped,
is in [`docs/final-report.md`](final-report.md). This table is the one under
test; that one is the summary.

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
| A write that worked says so | READY | UI-006. The interface flashes a *code*; the shell translates it, so a confirmation is in the reader's language. A test reads every `with('status', …)` in `src/` and demands a line for it in all six locales. |
| Error pages do not leak internals | READY | Analyser failure messages and storage paths are stripped at the query boundary. |

## Auth and identity

| Control | Verdict | Notes |
|---|---|---|
| Session auth, first OWNER at install | READY | |
| OWNER / ADMIN / MEMBER, MEMBER read-only | READY | Guards on routes, asserted by posting past hidden buttons. |
| Deactivation rather than deletion | READY | `active` middleware drops access at the next request. |
| No self-registration | READY | |
| No mass assignment of `role` / `is_active` | READY | |
| Accounts are administered from the product | READY | USR-001. `sanitube:user:create` was the only way to make an account, and role and `is_active` had no write path at all — an installation could be set up over SSH and never staffed from inside it. `/users` creates, promotes, demotes, deactivates and reactivates, and every change is audited with the person who asked. |
| The last owner cannot be removed | READY | USR-001. The rule was written in `UserRole`'s docblock and enforced nowhere, so an installation could be left with no account able to administer it — unrecoverable from inside the product. Two guards make it unreachable in a single request (only an owner may touch an owner; nobody administers themselves), and the count behind them is taken under `lockForUpdate` for the case they do not cover: two owners removing each other at the same time. A deactivated owner is not counted, because an owner who cannot sign in is not somebody who can administer anything. |
| Ownership and credentials are the owner's alone | READY | USR-001. An administrator operates the platform and does not replace the key it pays a supplier with, or the token a worker authenticates with — that is the change that turns administering an installation into taking it over. The gate is on the write, not on the field: an administrator still reads whether a credential is configured, which is an operational question during an outage. A refused secret takes the whole submission with it rather than saving the harmless half. |
| Deleting an account is not offered | READY | USR-001. `audit_events.actor_id` is `restrictOnDelete`, so the database refuses to delete anybody with history — a delete button would work only for accounts that had never done anything, and fail for exactly the people somebody wants gone. Deactivation is the operation, and the screen says why. |
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
| Tracks, artists, contributors, compositions | READY | The recording and the work are separate, with their own roles, their own identifiers and their own credits. |
| The work reaches the distributor, not only the recording | READY | DIST-008. Every delivery described who engineered the master and said nothing about who wrote the song — on a platform that had already built the writer side, normalised ISWCs and IPIs, and enforced the splits. `PackagedWriter` is a distinct type from `PackagedContributor`: one list would let a reader take a mastering engineer for a rights holder. ADR-0021. |
| A writer share is metadata, never money | READY | DIST-008. Passed through exactly as captured; nothing computes anything from it. Earnings stay with the distributor. |
| Track credits from the interface | READY | CAT-002. |
| Readiness earned, never assigned | READY | I3 re-run on every attempt. |
| Nothing else may grant it | READY | CAT-003. Three states are granted after a check and read as authority in thirty-odd places; a scan holds that they are written nowhere else. Guarding the column at runtime was tried and reverted — it broke 450 tests to stop something no code does. The scan's limit is stated: it sees a state written, not one handed to a method that writes it. |
| ISRC / UPC / EAN never invented | READY | Assigned deliberately; format-normalised. |
| Revoked identifiers never presented as active | READY | Scoped at the query boundary. |

## Import

| Control | Verdict | Notes |
|---|---|---|
| A relayed upload is refused for its real reason | READY | UPL-004, found in production: a genuine 4.7 MB MP3 answered "le fichier n'a pas pu être déposé". The import screen promised the configured ceiling (2 GB) while a relayed deposit dies at `post_max_size`; the rule that reconciles the two existed on the single-file screen only. It now lives in `UploadAdmission` and both screens read it, the screen refuses oversize files before sending and names whose limit it is, a body PHP discarded answers HOST_UPLOAD_LIMIT (413) with the number instead of "the file field is required", and the doctor reports the disagreement before a person meets it. |
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
| **Certified against a real remote worker** | BLOCKED_EXTERNAL | Never executed against one — but WRK-003 means somebody now can. `sanitube:worker:check` proves the handshake, the capability vocabulary, that it refuses work it does not announce, and that its token is enforced. A real job round-trip is named as not covered rather than approximated. |
| A degraded capability reaches the doctor | READY | OBS-004. It reported only what was *unavailable and required*, so every degraded state — object storage that cannot issue expiring URLs, a mailer that delivers nothing, an untested database driver — was told to `sanitube:health` and to nobody running the go-live command. Queue and scheduler are deferred to the doctor's own checks, named with reasons, because a second opinion can quietly start disagreeing with the guard it duplicates. |
| A worker can be certified before it is trusted | READY | WRK-003. The counterpart to `sanitube:storage:check --certify`, which the worker had no equivalent of. Read-only against the worker's world; prints neither the token nor any address. |

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
| Delivery without a distributor API | READY | DIST-004. `sanitube:distribution:export` builds a metadata sheet and a checked file list for a person to upload to a portal. The columns are configuration; no distributor is named in the code. Not a submission: no delivery row, nothing irreversible. CLI and browser alike. |
| Delivery reachable without a shell | READY | DIST-005. The package screen builds it, downloads the sheet and mints one link per master through the ordinary asset-preview endpoint — same policy, throttle and audit line. A cPanel installation has no shell, so a CLI-only delivery path is one that installation does not have. |
| The *submission* path uses that description | READY | DIST-007. `validateRelease`, `prepareRelease` and `submitRelease` all take `ReleasePackage`. Assembled once per submission and handed to both adapter calls, so "what did we send" has one answer. ADR-0020. A reflection test holds the contract, a source scan holds that no adapter imports a catalogue type, and a walk holds that the production path submits the package it assembled. |
| One description of what crosses to a distributor | READY | DIST-006. The exporter renders `ReleasePackage` rather than walking the aggregate itself — the mistake that type exists to prevent, and one DIST-004 made. Asserted on the exporter's imports, not by comparing two outputs that agree today. |
| Nothing is invented to fill a delivery cell | READY | DIST-004. An unassigned ISRC is an empty cell and a warning, never a generated code. A revoked identifier is never exported — the one place presenting a withdrawn code would put it back into circulation. |
| Real distributor (Too Lost / TuneCore) | BLOCKED_EXTERNAL | DIST-002. Their API is not invented here. The contract they would implement now takes `ReleasePackage` (DIST-007), so the first adapter cannot repeat DIST-004's defect. |
| A release with a track that has no master cannot be submitted | READY | DIST-007. It never was deliverable — the manual export path already refused it — but the submission path did not check, because the adapter was handed the aggregate and never looked. Found by the contract change, in the suite's own fixture. |

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
| Work that failed and was never looked at is reported | READY | OBS-003. The backlog check cannot see this and that is why there are two: a worker consuming briskly and failing every job leaves `jobs` empty, so the backlog reads READY while the catalogue quietly stops growing. Discarding failures on the `null` driver is itself reported, rather than counted as none. |
| A queue nobody works is reported | READY | OBS-002. The driver check answers "is work queued rather than run inline"; it never answered "does anybody pick it up". Both deployment guides tell an operator to add a `queue:work` cron or systemd unit, and nothing checked that they had. The oldest unreserved job's age separates a busy queue from a dead one; a count cannot. |
| A backup configuration that can never run is reported before it fails | READY | DEP-006. The doctor resolves the include paths and reports the refusal. Freshness answers when the last backup was; this answers whether there will be another. |
| Included paths contained | READY | DEP-005. An include path that resolves outside the installation, that is the application root, or that touches the backup destination is refused before the directory is created. |
| A backup never contains a backup | READY | DEP-005. `storage` is the obvious entry and used to copy every previous backup into the new one, doubling each run. |
| `.env` never in a backup | READY | DEP-005. Never copied, whatever the configuration says; stated on every manifest; and a backup naming one is refused on restore before anything is written. |
| Backups encrypted at rest | NOT_READY | They are not. Treat the destination as you would the database — `docs/deployment/backup.md` says so. Off-machine copies are the operator's, and so is their encryption. |

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
| `sanitube:host` — read the machine, suggest a profile | READY | DEP-007. File reads and PATH lookups only — no process is ever executed to inspect. Five profiles: CPANEL, VPS_CORE, VPS_CORE_AND_WORKER, WORKER_ONLY, CORE_ONLY_GENERIC; the advisor only suggests the three that are detectable and names the two that are choices. cPanel evidence outranks everything a VPS would have, because a cPanel server has root and systemd for cPanel's own use. |
| Installer journal — resumable, recorded, never authorising | READY | DEP-008. Every stage outcome from both installers (shell and web) lands in storage/installer/journal.json, 0600, scrubbed through the shared credential rule. `sanitube:install --status` reads it; `--profile` records the chosen shape and the completion message speaks its language. The journal records and never authorises — skipping is re-decided from the machine, so a stale journal cannot contradict the filesystem. A corrupt journal is preserved aside, not replaced. |
| Unattended install from a protected config file | READY | DEP-009. `--config` reads the .env dialect; the allowlist is the web installer's, held equal by a reflection test. A file readable or writable beyond its owner is refused before a byte is parsed; unknown keys stop the run by name; errors quote line numbers, never content. Owner password from the file or SANITUBE_OWNER_PASSWORD — never argv. |
| `sanitube:provision` — cron line, systemd units, nginx block | READY | DEP-010. Generated from validated inputs; a value that could smuggle a directive (newline, brace, semicolon) is refused, never escaped, and the refusal never echoes the value. Queue is a template unit — concurrency is how many instances the operator enables, never a number in a file. Instance-named (SANITUBE_INSTANCE_NAME) so two installations on one VPS cannot collide. Renders only: installing into /etc, `nginx -t` and `systemd-analyze verify` stay with root, where root belongs. nginx block is HTTP-only on purpose — certbot --nginx adds the TLS half to a real certificate. |
| `sanitube:frontend:install` — the bundle lands atomically, for its own commit | READY | DEP-011. CI's artifact (zip or extracted directory) staged beside the live build and swapped with two renames — a request mid-install sees the old build or the new, never half. Archive entries are streamed to paths this code chose: traversal, absolute names and symlinks refuse with nothing installed, and the hostile path is never echoed. --sha is checked against HEAD read from .git as files (loose ref and packed-refs); on a non-git host the sha becomes the record. Stale hashed assets leave with their whole directory. The command downloads nothing, deliberately: a GitHub token in a server's environment is a bigger door than the step is worth. |
| `bin/update.sh` + deploy lock — one deploy at a time, to a named revision | READY | DEP-012. The revision is a required argument with no default, verified before the site blinks; a dirty tree refuses (uncommitted fix or intrusion — the update runs over neither); the database backup comes before maintenance mode; maintenance is released by trap on every exit; the doctor has the last word after the site is back up. sanitube:deploy takes a file lock — a second run is refused with the holder's age; stale after an hour so one crash cannot wedge deploys forever; released even when a stage fails. |
| `sanitube:providers` — six states, no vague booleans | READY | DEP-013. One certification model for every external provider: NOT_CONFIGURED, CODE_READY, CONFIGURED_UNCERTIFIED, CERTIFIED, BLOCKED_EXTERNAL, UNAVAILABLE. CERTIFIED is earned only by the commands that talk to real services (storage --certify, worker:check) recording into a ledger, fingerprinted to the exact configuration — a changed bucket, endpoint or worker address silently un-certifies. The test suite binds the ledger elsewhere, so a test's pass can never read as a real certification. |
| `sanitube:smoke` — real HTTP, forbidden paths included | READY | DEP-014. GET only, unauthenticated, bounded timeouts, against APP_URL: login renders, unauthenticated dashboard redirects (a 200 there is treated as the fire it is), /up answers, a manifest-named hashed asset serves, and /.env /.git/config /composer.json /storage/logs /config /vendor must not answer 2xx whatever the status line. bin/update.sh runs it after the site is back up; a failure exits non-zero after up, because up on new code beats dark. Verdict logic held against faked HTTP; the real run is what deployment is for. |
| Worker protocol version + token minting | READY | DEP-015. The wire has a version, separate from the application version — Core and worker do not deploy in lockstep. A known mismatch refuses jobs before anything is sent (a payload read under the wrong protocol does not fail, it does the wrong thing quietly); absent announcement is version 1 by definition, so every worker that worked yesterday still does. worker:check names both numbers on mismatch. sanitube:worker:token mints once to the terminal and stores nothing; nginx port is an input. |
| `bin/bootstrap.sh` — detection before action, nothing piped to a shell | READY | DEP-016. Reads the machine, prints the exact commands, installs nothing without --yes-install-packages + root + a recognised package manager. The one download it can perform (Composer's installer) is SHA-384-verified per the publisher's documented procedure and removed on mismatch. Hands over to sanitube:host / sanitube:install — it is not a second installer. |
| Script hygiene held as a guardrail, not a review | READY | DEP-016. Every bin/*.sh, discovered not listed: set -euo pipefail, no eval, no backticks in code, no download piped to a shell, executable, bash shebang. The audit is a test, so the script somebody adds next year is in scope the moment it exists. |
| Bootstrap detection proven on real distros in CI | READY | DEP-016. Ubuntu 24.04, Debian 12 and AlmaLinux 9 containers run the bootstrap: it names the right package manager, notices PHP is absent, and refuses to act unasked. Deliberately narrow — the full converge is the real-VPS certification step, not a CI step. |
| Nightly backup scheduled, freshness judged separately | READY | DEP-017. SANITUBE_BACKUP_AT (empty disables); the doctor's freshness check stays separate so a scheduled backup that stopped succeeding cannot hide behind being scheduled. |
| Disk thresholds reach the doctor | READY | DEP-017. Application and backup volumes judged separately, in absolute megabytes (what the platform needs is measured in MB, not fractions of somebody's disk); warn and blocker levels configurable, blocker stops a deploy because migrating on a full disk fails halfway in the worst way. |
| `sanitube:self-test` — one sitting, no duplication | READY | DEP-017. A conductor only: health, doctor, providers, and smoke when APP_URL is usable. Fails if the doctor or the smoke failed. |
| The real-world certification plan | READY | DEP-017. docs/deployment/certification-plan.md — A through K, runnable with shipped code, no development edits. Until executed on a real host the automation is CODE_READY and says so. |
| `sanitube:assets:relocate` — the catalogue moves under proof | READY | DEP-018 / ADR-0022. Stream-copy to the target provider, verify the *target* against what the asset has always claimed (never a fresh source hash, which would bless corrupt bytes with matching wrong checksums), then a sanctioned save the observer consumes — identity fields stay frozen forever, a bare disk change still throws, a refused mixed save spends the sanction too (its test caught the first version not doing so). Batched, resumable (done is detected, not remembered), one failing file strands nothing, and no source is ever deleted — the service has no delete call to make against one, by design. |
| `sanitube:mail:certify` — configured and delivers are different facts | READY | DEP-019. One real message, to an address the operator names, only when the operator asks — nothing schedules it. Acceptance by the transport becomes a ledger record fingerprinted to the mailer configuration; change the relay and the standing quietly returns to CONFIGURED_UNCERTIFIED. A log/array mailer has nothing to certify and says so. Transport errors are not echoed — their words carry hosts and sometimes credentials. |
| Multi-instance independence + GPU facts | READY | DEP-020. The cache prefix keys to SANITUBE_INSTANCE_NAME so two installations sharing a cache backend cannot collide (operational identity — renaming what the screens call the app changes nothing). Host inspection reads the GPU fact that matters: the kernel driver marker, not the binary a package install leaves behind, and the worker-profile advice states it both ways without ever blocking — a CPU-only worker is a legitimate analysis machine. Firewall/SSH, log rotation, removal and object-storage-is-not-a-backup are documented as operator acts, never sprung. |

## Security

| Control | Verdict | Notes |
|---|---|---|
| Authorization on routes, not controllers | READY | |
| Every route is guarded, or named with a reason | READY | SEC-002. Read from the router after every module registers: no write without a credential, no web write without a role, no read without a guard. Eleven exemptions, each a sentence. |
| MEMBER cannot write, proven by posting past the UI | READY | |
| No internal ids in URLs | READY | |
| CSRF on every write | READY | Laravel `web` group. |
| No secrets in logs, payloads or diagnostics | READY | Asserted, including in the doctor. |
| No secret or address in a failure message a person reads | READY | OBS-001. The failed-jobs screen rendered an S3 client's 403 verbatim — presigned signature and all — because the first line was truncated rather than scrubbed. One rule now, `CredentialRedactor::scrub()`: configured secrets masked, every address removed, at four boundaries. |
| Delivery failure text carries no address | READY | OBS-001. `SubmitDelivery` stores a provider's own words in `failure_reason` and `response_summary`; `sync`, `reconcile` and `takedown` reach the recorder without passing the failure path, so it is scrubbed there too. Read boundaries scrubbed for rows written earlier. |
| A bare hostname is *not* removed from a failure message | NOT_READY | OBS-001, deliberately. The rule is anchored on a scheme, because a heuristic loose enough to catch `distributor.example port 443` catches every dotted word in every message. What it removes is what carries a credential. |
| Capability details carry no credential | READY | OBS-001. `CapabilityRegistry` wraps every detector including object storage, and resolving an S3-compatible provider builds a client from the configured key and secret. Scrubbed in the one `Capability` constructor, like `StorageHealth` and `CertificationCheck`. |
| Stored failure text carries no address | READY | OBS-001. `ingestion_items.failure_message` and `audio_analyses.failure_message` are durable and go into every backup, so they are scrubbed on write *and* on read — rows written before the rule are already in the column. |
| Preview URLs signed, expiring, throttled | READY | |
| No permanent public URL for any asset | READY | STO-005. The contract has no such method; no disk declares `url`. |
| Every storage disk declares private visibility | READY | STO-005. Asserted per configured provider. |
| Storage certified against the real service | READY | STO-006. `--certify` covers move, signed read, presigned write. CORS remains browser-only. |
| Restore path traversal refused | READY | |
| Full external penetration test | BLOCKED_EXTERNAL | Internal audit only. |

## Settings

| Control | Verdict | Notes |
|---|---|---|
| Worker and mail are administrable from the dashboard | READY | CFG-001. Both join the existing section model: the worker's *identity* is shown, never its URL — an address on a settings screen is an address in a screenshot — and its token is present-or-absent like every other secret. Mail exposes host, port, username, from-address; the password is a secret. The guardrail that every writable variable appears on the screen caught a mail variable that did not. |
| "Test connection", from the dashboard | READY | CFG-001. `POST /settings/test` runs the *same* probes the commands run (`storage:check --certify`, `worker:check`) so a screen cannot disagree with the deploy gate, and a pass records a real certification in the shared ledger — a button cannot invent CERTIFIED. The target is a closed vocabulary held in one place (`ConnectionProbe`), read by the payload, the request rule and the controller alike: a section offering a probe the endpoint rejects would need that file to disagree with itself. Never an address the caller composes — that is the line between a test button and an SSRF tool. Details are scrubbed on the way out, because a failed signed read quotes its own signed URL, which is fine in a terminal and not in a browser. The button is on the screen: an endpoint with no caller is not a feature. |
| Every provider family can be chosen from the screen | READY | CFG-002. Generation, AI and distribution join storage: the selection is a writable variable constrained by `in:` to the names the same screen offers, both read from the declaration through `SelectableProviders`. Before this, three sections published their options as text and were writable nowhere — the screen listed choices nobody could make. |
| Self-hosted ACE-Step is selectable | READY | CFG-002. The adapter and its resolution arm existed since ADR-0019; the declaration in `config/generation.php` did not, so `names()` never mentioned the only real generation engine SaniTube has, and setting it by hand produced `UnknownGenerationProvider` with the section reporting the installation's own provider as unknown. It needs no credentials of its own — everything it reaches is the worker's. |
| A provider's credentials follow the provider in use | READY | CFG-002. The AI variables are gated on the selection the way storage's always were. They had been writable in every configuration and visible in one, so on a Claude installation the two OpenAI variables were editable and invisible. |
| The screen and the writer agree, configuration by configuration | READY | CFG-002. The parity test was a union across configurations, which proved only that nothing was writable that *no* screen ever shows. It now asserts both directions per configuration, so a variable published without a writer — a field that looks editable and refuses — fails too. One exemption remains, named with its reason. |
| The Studio says where to configure what it says is unconfigured | READY | CFG-002. The banner was honest and offered nowhere to act; the only route from it to the setting was knowing the URL. Shown only to somebody the settings screen would admit, because a link that answers 403 reads as breakage rather than as permission. |
| Mail can be proved from the dashboard | READY | CFG-003. `sanitube:mail:certify` existed and was reachable only over SSH, so "will password resets arrive?" was a question for somebody with a shell. The sending moved into `CertifyMail`, which the command and the button both call — one implementation, no second copy to disagree. **The recipient is the signed-in operator's own address and can never come from the request**: a recipient read out of the payload would make an authenticated account into a mailer pointed at a stranger's inbox. A refusal is FAILED without repeating what the transport said, because a refusal quotes the host it could not authenticate against and sometimes the username it tried. A refused send records nothing. |
| The certification standings are visible without SSH | READY | CFG-003. The six-state vocabulary existed for a phase and had exactly one reader — `sanitube:providers` — so the screen whose whole subject is what is configured could not say whether any of it had been proved. The settings screen now publishes the same assessment the command reports, asserted identical by test. It is a read of the ledger, never a probe: rendering the page must not depend on nine providers answering, or an outage takes down the page somebody opens to find out why. |
| Audio, queue, backup, automation and health are administrable | READY | CFG-004. Five sections that did not exist. Each was configured by an environment variable an operator had no way to see, let alone change, without a shell — including the upload ceilings, which is the pairing UPL-004 was about: configured, invisible, and quietly smaller than the host allows. |
| An executable path is never writable from a form | READY | CFG-004. `SANITUBE_FFPROBE_PATH` and `SANITUBE_FPCALC_PATH` are published and absent from the allow-list. The platform *runs* whatever they name, so a field here would turn a stolen admin session into arbitrary command execution — a different order of damage from every other setting on the page. Not rejected by a rule: never heard of by the writer, which is the stronger refusal. |
| The queue connection is never switched from a form | READY | CFG-004. Repointing it does not move the jobs already sitting in the old connection; they are simply never run again, silently, while the screen reports a successful save. A migration, not a setting — the same reason `DB_*` has never been on this form. |
| A backup destination is refused where the mistake is made | READY | CFG-004. `BackupRepository::destination()` already refused a path inside the web root on every call, which holds however the value got there. The form now refuses it too, because accepting a destination the next run will reject is letting somebody save a configuration that fails at two in the morning. Compared with the separator, so a `public_html` sibling of `public` is not mistaken for a child of it. |
| A refused setting says so on the field it came from | READY | CFG-004. Every writable setting is validated and, until now, no refusal had anywhere to appear: the form came back unchanged, which reads as "it saved". |
| A trashed asset can never become a master | READY | TRASH-001. `TrashAsset` had always guarded one direction — an asset a track already uses cannot be trashed — and the reverse was never guarded. Nothing stopped a track being *given* a trashed asset, and nothing looked at `trashed_at` while a delivery package was built, so bytes a reviewer rejected as a wrong file, a bad transfer or a confirmed duplicate were what left for the distributor. Every screen on the way looked correct, because they all hide trashed assets already. The refusal lives on the model, so a third service that assigns a master inherits it rather than having to remember. |
| A package refuses a master trashed after the fact | READY | TRASH-001. Defence in depth: the model refuses to take a trashed master, so reaching the package check means the asset was set aside *after* the track was pointed at it. Delivering bytes somebody rejected is worse than delivering nothing, and it is the kind of failure nobody notices until a distributor has published it. |
| The duplicate queue shows what is left to decide | READY | DUP-001. The list had no default filter, so every finding a reviewer had already confirmed or rejected came back on the next visit, and the only way to see what remained was to re-select "proposed" every time. An absent `decision` now means undecided; an explicit empty one means everything, which is how a reviewer looks back at what they answered. The payload carries an open count — a cursor-paginated list has no total by design, so nothing told anybody whether the backlog was shrinking. |
| The trash is reachable and restorable from the catalogue | READY | DUP-001. The read model supported `?trashed=only` from the start and nothing on any screen ever sent it, so an asset set aside as a wrong upload or unusable audio — with no duplicate finding beside it — could be listed only by typing the query string by hand and restored from nowhere at all. Its own comment said so: an asset nobody can find is one nobody can restore. |
| What a supplier is allowed to cost is on the screen | READY | CFG-005. AI and generation both enforce rolling quotas and a circuit breaker on every call, and both were configured by environment variables that appeared on no screen — so the one control that bounds what a backfill costs could be set only over SSH. Zero stays sayable: it means no ceiling, it is the shipped default, and a `min:1` would have made the shipped configuration unrepresentable on the form that edits it. |
| The model an installation pays for is the operator's | READY | CFG-005. Published and writable, and deliberately **not** a closed list — vendors publish new models faster than this platform ships, and an `in:` rule would mean a release is required before somebody can use the model they are already paying for. |
| ACE-Step's own settings are documented where an operator looks | READY | CFG-005. CFG-002 made the engine selectable and `.env.example` named none of its variables, so choosing it gave an operator a provider with no documented way to point it at anything. The endpoint and the output root — the only directory the worker will open a file from — are named first, because they are the two without which nothing runs. |
| The global stop is visible on the operations screen | READY | OPS-002. The switch existed, worked, and was reported nowhere. An installation somebody paused yesterday looked identical to a healthy one — same queue page, same job list, same green scheduler — while nothing was processed. That is the worse half: an operator can live without a button and cannot diagnose a silent halt they have no way to see. |
| The global stop is reachable without a shell | READY | OPS-002. `sanitube:work:pause` was the only way to press the control an operator reaches for *while watching something go wrong*, at the moment they are least able to go and find a terminal. The reason is a closed vocabulary — free text would be a sentence in one language shown to everybody, in an audit record forever, and the field somebody eventually pastes a customer's name into. An absent reason still works: a required one would make the emergency control need a decision first. |
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
| Install → OWNER → login | READY | E2E-003. The walk cannot install — it would rebuild the database the suite is connected to, and it names that exclusion. The joint after it is walked in `InstallerTest`: the owner the installer created signs in through the real form and opens an administrator's screen. Neither suite held that seam before; a mutation writing the wrong password passed every other test in both. |

## Scale

| Control | Verdict | Notes |
|---|---|---|
| No screen costs more queries as the catalogue grows | READY | PERF-001, measured at two sizes. |
| The payload comparison measures the catalogue and not the fixture | READY | PERF-003. Two artefacts together came to almost exactly the old kilobyte tolerance: the second seeding wrote audit rows carrying an address the first one's lacked, and it created a second ingestion batch. Removed rather than tolerated; growth is now nought to five bytes and the bound is 256. |
| Every measured screen is measured with rows in it | READY | PERF-002. Five of the nine screens had been measured against empty tables; a guard now fails by name when a listed screen renders nothing. |
| `/enrichment/suggestions` costs the same per page whatever it holds | READY | PERF-004. It did not: `canonical` asked for the track mastering each asset and `measured` for that asset's newest analysis, both inside the row loop — fifty queries for a page of twenty-five. Found by reading the five screens the scale test cannot reach. Held by a count in `SuggestionReviewScreenTest`, where the objects live. |
| Index screens this test does not reach | READY | PERF-005. The five PERF-002 named — enrichment, distribution, production, generations, projects — are measured: the fixture now builds the chain each needed, and every one renders a full page at both sizes. Removing an eager load or unbounding a page on any of the five fails the suite. |
| The list of screens cannot drift out of step with the application | READY | PERF-005. Screens used to be enumerated by hand, which is right on the day it is written and cannot report a screen nobody remembered to add. The list is now checked against the router: every parameterless `GET` into the interface module must be measured, named as not yet measured with what it would need, or named as something whose rows do not grow with the catalogue. A new screen fails by name; a written-down screen that is no longer a route fails too. |

## Accessibility

| Control | Verdict | Notes |
|---|---|---|
| Modal focus trap, restore, and background inert | READY | Ten tests on the primitive, and UI-A11Y-001 holds that the screens reach their controls through the design system rather than around it. |
| Keyboard navigation and tab order per screen | NOT_READY | Not audited. The primitives behave; nothing checks that a given screen's controls come in a sensible order, or that there is a way past a long filter bar. Thirty-seven screens, and a scan cannot answer it. |
| Contrast, light / dark / system | READY | Tokens; no hardcoded colours. |
| Every form control carries its label | READY | UI-A11Y-001. Three raw inputs on the enrichment review screen were orphaning the label `FormField` provides — the exact failure that component's own comment describes — and two off-screen file inputs sat unnamed in the accessibility tree. A scan holds it, with three exemptions and a sentence each. |
| Screen-reader semantics beyond labelling | NOT_READY | Landmarks, reading order and live regions are unaudited. A scan can hold "every control has a name"; it cannot hold "this page makes sense read aloud", and claiming otherwise from the part that is checkable is how a ledger stops being worth reading. |

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
