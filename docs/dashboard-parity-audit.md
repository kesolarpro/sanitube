# SaniTube — Backend ↔ Dashboard Parity Audit

**Date** 2026-08-22 · **Branch** `main` @ `22b1b29` · **Method** direct inspection of
the repository, not of the documentation about it.

Every line below is classified. Where something does not exist, it says so.
`SHOULD` is never mixed with `IS`.

| Class | Meaning |
|---|---|
| `IMPLEMENTED` | Built, reachable, with a production caller |
| `PARTIAL` | Exists and is reachable, but incomplete for the job it names |
| `BACKEND_ONLY` | Works, has no route or screen — CLI, config or event only |
| `NOT_IMPLEMENTED` | Does not exist in the repository |
| `EXTERNAL_BLOCKED` | Complete on our side; needs a credential, host or counterparty |

---

## 1. Executive summary

SaniTube's backend is substantially larger than its dashboard. The catalogue,
ingestion, studio, releases and distribution paths are genuinely complete and
genuinely reachable. The **operator** paths are not: two entire provider
families have adapters, credentials, quotas and circuit breakers with **no
settings section at all**, there is **no user administration of any kind**, and
a production plan — the object that drives autonomous operation — **cannot be
created from the interface**.

The five findings that matter most:

1. **Artwork and Transcription are invisible providers.** 27 environment
   variables between them, including two separate OpenAI API keys, quotas and
   circuit breakers. `SettingsQuery` publishes twelve sections and neither is
   one of them. An operator cannot see whether artwork generation is
   configured, cannot enter its key, and cannot tell why it declines.
2. **There is no user management.** No route, no screen, no controller. The
   only way to create a user is `sanitube:user:create` over SSH. Roles cannot
   be changed, accounts cannot be deactivated, and the platform ships with
   three roles nobody can assign without a terminal.
3. **Production plans are read-and-steer only.** Pause, resume, set autonomy
   and cancel an occasion all exist. Create does not, and neither does editing
   cadence, target track count, or the editorial profile a plan draws from.
   `WriteEditorialProfile` has no controller anywhere.
4. **Six of nine provider standings can never reach CERTIFIED.** Only storage,
   worker and mail have a writer. AI, transcription, artwork, music generation
   and distributor are structurally stuck at `CONFIGURED_UNCERTIFIED` — the
   screen shows a status that cannot change, which reads as a fault and is
   actually a missing probe.
5. **Storage usage is reported nowhere.** Not on the dashboard, not on the
   operations screen, not in settings. An operator running out of R2 or disk
   finds out from the provider's own console.

**88 of 139 `SANITUBE_*` environment variables have no UI.** That is the single
number this audit is about.

---

## 2. Current navigation map

`src/Ui/Navigation/NavigationTree.php`. Seventeen entries, two of them dead.

| Entry | Route | Available to | Assessment |
|---|---|---|---|
| Dashboard | `/` | all | Useful. Thin — see §11 |
| Studio | `/studio` | all | Correct |
| **Library** (group) | — | all | 11 children, too flat — see below |
| ├ Import | `/ingestion/import` | all | Correct, correctly first |
| ├ Ingestion | `/ingestion/batches` | all | Name is vague; it is *Batches* |
| ├ Candidates | `/ingestion/candidates` | all | Correct |
| ├ Catalog | `/catalog/tracks` | all | **Mislabelled** — it is Tracks, not the catalogue |
| ├ Artists | `/catalog/artists` | all | Correct |
| ├ Contributors | `/catalog/contributors` | all | Correct |
| ├ Compositions | `/catalog/compositions` | all | Correct |
| ├ Upload | `/assets/upload` | all | **Near-duplicate of Import** — see §17 |
| ├ Assets | `/catalog/assets` | all | Correct; now also the trash |
| ├ Duplicates | `/duplicates` | all | Correct |
| └ Suggestions | `/enrichment/suggestions` | all | Correct |
| Production | `/production` | all | Correct |
| Releases | `/releases` | all | Correct |
| Distribution | `/distribution` | all | Correct |
| Rights | — | all | **Dead entry**, `available: false`, deliberate placeholder |
| Analytics | — | all | **Dead entry**, `available: false` |
| Jobs | `/system/jobs` | admin | Correct |
| Operations | `/system/operations` | admin | Correct |
| Audit | `/system/audit` | admin | Correct |
| Design system | `/design-system` | **all** | **Should be admin-only or dropped** — it is a developer artefact shown to every member |
| Settings | `/settings` | admin | Correct |

**Problems.** The Library group carries eleven items at one level, mixing three
different activities: getting files in (import, upload, batches, candidates),
the catalogue itself (tracks, artists, contributors, compositions), and asset
hygiene (assets, duplicates, suggestions). There is no group for administration
— Jobs, Operations, Audit and Settings float at top level beside business
sections. Two entries lead nowhere. One developer screen is visible to
everyone.

---

## 3. Backend capability inventory

28 modules, 834 PHP files, 105 web routes, 40 API routes, **35 artisan
commands**, 12 job classes, 47 audit actions, 3 roles.

| Module | Files | Principal capability | Reachable from UI |
|---|---|---|---|
| AI | 18 | Prompt execution, quota, circuit breaker, invocation ledger | Indirect only |
| Api | 45 | Public read + write API, token-gated | No management UI |
| Artists | 7 | Artist records | Yes |
| **Artwork** | **36** | Cover measurement + AI generation, quota, circuit breaker | **Generation button only; no settings** |
| Assets | 34 | Storage, checksums, trash, relocation, admission | Mostly |
| Audit | 12 | 47 recorded action types, pruning | Read-only screen |
| Catalog | 46 | Tracks, compositions, identifiers, credits | Yes |
| Contributors | 3 | Contributor records | Yes |
| Deduplication | 12 | Checksum + fingerprint detection, decisions | Yes |
| Deployment | 32 | Doctor, smoke, self-test, host, backup, restore, frontend install | **CLI only** |
| Distribution | 33 | Delivery, package export, takedown, reconcile | Yes |
| **Editorial** | 5 | Editorial profiles that drive production plans | **No UI at all** |
| Enrichment | 15 | Metadata suggestions, review | Yes |
| Foundation | 17 | Shared contracts | n/a |
| **Identity** | 17 | Auth, roles, password reset, user creation | **Login only; no administration** |
| Ingestion | 49 | Batches, items, candidates, manifests, bulk review | Yes |
| Installer | 17 | Install, provision, journal, resume | CLI only (correct) |
| Localization | 8 | 6 languages | Implicit |
| Media | 46 | FFprobe analysis, Chromaprint fingerprint, execution strategy | Partial |
| MusicGeneration | 61 | Providers, projects, generations, results, polling | Yes |
| Observability | 34 | Health, capabilities, certification standings, heartbeat | Partial |
| Operations | 10 | Global pause, backlog guard, work admission | Yes (OPS-002) |
| Production | 21 | Plans, slots, autonomy, claim leases | **Steer only, no create** |
| Releases | 27 | Release builder, validation, packaging | Yes |
| Storage | 28 | Providers, certification, relocation, credential redaction | Partial |
| **Transcription** | **21** | Whisper transcription, eligibility, backlog | **No settings, no trigger UI beyond per-asset** |
| Ui | 162 | 37 screens, 39 read models | — |
| Worker | 18 | Handshake, protocol version, capabilities, token | Partial |

---

## 4. Backend ↔ Dashboard parity matrix

Ordered by severity. `P` is the priority defined in §14.

| Module | Capability | Backend | Prod. caller | Current UI | Route | Actions | Status shown | Config | Role | Should be in UI | Where | Missing | P | Risk |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Identity | Create user | `IMPLEMENTED` | CLI | **none** | — | — | — | — | — | **YES** | Users | Whole screen | **P0** | high |
| Identity | Change role | `NOT_IMPLEMENTED` | — | none | — | — | — | — | — | **YES** | Users | Service + screen | **P0** | high |
| Identity | Deactivate account | `PARTIAL` (`is_active` column read) | middleware | none | — | — | — | — | — | **YES** | Users | Write path + screen | **P0** | high |
| Artwork | Provider config + key | `BACKEND_ONLY` | `OpenAiImageProvider` | **none** | — | — | — | env only | — | **YES** | Settings › Artwork | Whole section | **P0** | high |
| Artwork | Quotas + circuit breaker | `BACKEND_ONLY` | `ArtworkSpendGuard` | none | — | — | — | env only | — | **YES** | Settings › Artwork | Whole section | **P0** | high |
| Artwork | Generation history | `IMPLEMENTED` (`artwork_generations`) | job | **none** | — | — | — | — | — | **YES** | Releases / Studio | List + statuses | P1 | med |
| Transcription | Provider config + key | `BACKEND_ONLY` | `OpenAiTranscriptionProvider` | **none** | — | — | — | env only | — | **YES** | Settings › Transcription | Whole section | **P0** | high |
| Transcription | Backlog run | `BACKEND_ONLY` | CLI | none | — | — | — | — | — | YES | Operations | Trigger + progress | P1 | med |
| Production | **Create plan** | `BACKEND_ONLY` (`WriteProductionPlan`) | CLI/seed | **none** | — | — | — | DB | catalogue | **YES** | Production | Create + edit form | **P0** | high |
| Production | Cadence / target count | `IMPLEMENTED` (columns) | — | read-only | `/production` | — | shown | DB | — | **YES** | Production | Edit | **P0** | high |
| Editorial | Editorial profiles | `BACKEND_ONLY` | `WriteEditorialProfile` | **none** | — | — | — | DB | — | **YES** | Production › Profiles | Whole screen | P1 | med |
| Storage | Usage / capacity | `NOT_IMPLEMENTED` | — | none | — | — | — | — | — | **YES** | Dashboard + Settings | Measurement + display | **P0** | high |
| Storage | Provider select | `IMPLEMENTED` | `StorageManager` | settings | `/settings` | save | partial | env | admin | YES | Settings › Storage | Per-provider card | P1 | med |
| Storage | Connection test | `IMPLEMENTED` | `POST /settings/test` | settings | `/settings` | test | CONNECTED/DEGRADED/FAILED | — | admin | — | — | — | — | — |
| Storage | Relocate catalogue | `BACKEND_ONLY` | `sanitube:assets:relocate` | none | — | — | — | — | — | **YES, guarded** | Settings › Storage | Confirmed, progress-reported | P1 | **high** |
| Deployment | Doctor | `BACKEND_ONLY` | `sanitube:doctor` | none | — | — | — | — | — | **YES** | System | Run + findings | P1 | low |
| Deployment | Smoke test | `BACKEND_ONLY` | `sanitube:smoke` | none | — | — | — | — | — | YES | System | Run + findings | P2 | low |
| Deployment | Self-test | `BACKEND_ONLY` | `sanitube:self-test` | none | — | — | — | — | — | YES | System | Run + findings | P2 | low |
| Deployment | Host facts | `BACKEND_ONLY` | `sanitube:host` | none | — | — | — | — | — | YES | System | Read-only panel | P2 | low |
| Deployment | Backup — run now | `BACKEND_ONLY` | scheduler | read-only | `/system/operations` | — | last taken, count | env | admin | **YES, guarded** | Backups | Trigger + history | P1 | med |
| Deployment | Backup — list | `BACKEND_ONLY` (`BackupRepository`) | CLI | count only | — | — | partial | — | — | **YES** | Backups | Full list, sizes | P1 | med |
| Deployment | Restore | `BACKEND_ONLY` | `sanitube:restore` | none | — | — | — | — | — | **NO** | — | Stays CLI — §12 | — | **critical** |
| Deployment | App version / commit SHA | `NOT_IMPLEMENTED` | — | none | — | — | — | — | — | **YES** | System | Whole panel | P1 | med |
| Observability | Certification standings | `IMPLEMENTED` | `ProviderStandings` | settings | `/settings` | — | 9 standings | — | admin | — | — | 6 have no writer — see §6 | P1 | med |
| Observability | Health refresh | `IMPLEMENTED` | `POST …/refresh` | operations | `/system/operations` | refresh | yes | — | admin | — | — | — | — | — |
| Operations | Global pause | `IMPLEMENTED` | `POST …/pause` | operations | `/system/operations` | pause/resume | yes | — | admin | — | — | — | — | — |
| Operations | Queue backlog | `IMPLEMENTED` | `QueueQuery` | operations | `/system/operations` | — | driver, pending, failed | — | admin | — | — | — | — | — |
| Operations | Failed job retry | `IMPLEMENTED` | `FailedJobController` | jobs | `/system/jobs` | retry/forget | yes | — | admin | — | — | — | — | — |
| Deduplication | Thresholds | `BACKEND_ONLY` | `config/deduplication.php` | none | — | — | — | env only | — | YES | Settings › Duplicates | Whole section | P2 | low |
| Deduplication | Sweep existing catalogue | `BACKEND_ONLY` | `sanitube:duplicates:evaluate` | none | — | — | — | — | — | YES | Duplicates | Trigger + progress | P1 | med |
| Media | FFmpeg / Chromaprint availability | `IMPLEMENTED` | `CapabilityRegistry` | dashboard | `/` | — | capability chips | env | — | PARTIAL | System | Per-binary detail, version | P2 | low |
| Media | Re-analyse / re-fingerprint | `BACKEND_ONLY` | CLI | none | — | — | — | — | — | YES | Assets | Per-asset + backlog | P1 | med |
| Worker | Handshake + protocol | `IMPLEMENTED` | `POST /settings/test` | settings | `/settings` | test | CONNECTED/FAILED | env | admin | PARTIAL | Settings › Workers | Capabilities, last error, protocol | P1 | med |
| Worker | Mint token | `BACKEND_ONLY` | `sanitube:worker:token` | none | — | — | — | — | — | YES | Settings › Workers | Generate + copy-once | P1 | med |
| Ingestion | Limits (batch size, concurrency) | `BACKEND_ONLY` | `config/ingestion.php` | none | — | — | — | env only | — | YES | Settings › Ingestion | Whole section | P2 | low |
| Api | Token rotation | `PARTIAL` | settings secret | settings | `/settings` | replace | configured/not | env | admin | PARTIAL | Settings › API | Rotate action, last used | P2 | med |
| Audit | Export | `NOT_IMPLEMENTED` | — | screen | `/system/audit` | — | list + filters | — | admin | YES | Audit | CSV export | P2 | low |
| Audit | Prune | `BACKEND_ONLY` | `sanitube:audit:prune` | none | — | — | — | — | — | NO | — | Destructive, stays CLI | — | high |
| Distribution | Credentials status | `PARTIAL` | `DistributorManager` | settings | `/settings` | select | selected only | env | admin | YES | Settings › Distribution | Per-distributor fields + test | P1 | med |
| MusicGeneration | ACE-Step engine config | `BACKEND_ONLY` | worker host | none | — | — | — | env only | — | PARTIAL | Settings › Generation | Endpoint + output root read-only | P2 | med |
| Installer | Install / provision / deploy | `IMPLEMENTED` | CLI | none | — | — | — | — | — | **NO** | — | Stays CLI — §12 | — | **critical** |

---

## 5. Settings matrix

`SettingsQuery` publishes **12 sections / 59 variables**. `WritableSettings`
owns a subset. **88 of 139 `SANITUBE_*` variables appear nowhere.**

| Section | On screen | Writable | Missing | Verdict |
|---|---|---|---|---|
| Storage | 2 + provider fields | yes | usage, per-provider cards, relocate | `PARTIAL` |
| AI | 9 | yes | ledger text toggle | `IMPLEMENTED` |
| Music generation | 8 | yes | preference order, claim seconds, worker staging | `PARTIAL` |
| Distribution | 1 | yes | per-distributor credentials | `PARTIAL` |
| Workers | 4 | yes | timeouts, handshake timeout, token header | `PARTIAL` |
| Audio / media | 10 | 8 (2 read-only by design) | FFmpeg path, fpcalc length, per-kind maxima ×4 | `PARTIAL` |
| Email | 6 | yes | — | `IMPLEMENTED` |
| Queue / scheduler | 2 | 1 (connection read-only by design) | schedule visibility | `PARTIAL` |
| Backup | 3 | yes | history, run-now | `PARTIAL` |
| Production automation | 2 | yes | plan CRUD | `PARTIAL` |
| System / health | 3 | yes | stale-after, retention, rate limits | `PARTIAL` |
| API | 3 | yes | version, rotation | `PARTIAL` |
| **Artwork** | **0** | **no** | **19 variables** | **`NOT_IMPLEMENTED`** |
| **Transcription** | **0** | **no** | **8 variables** | **`NOT_IMPLEMENTED`** |
| **Deduplication** | **0** | **no** | **6 variables** | **`NOT_IMPLEMENTED`** |
| **Ingestion** | **0** | **no** | **6 variables** | **`NOT_IMPLEMENTED`** |
| **General (app)** | read-only block | **no** | name, locale, timezone | `PARTIAL` |

### Deliberately read-only, each with its reason

| Variable | Why it must not be a form field |
|---|---|
| `SANITUBE_FFPROBE_PATH`, `SANITUBE_FPCALC_PATH` | The platform *executes* them. A field here turns a stolen admin session into remote code execution |
| `SANITUBE_FFMPEG_PATH` | Same — and it is currently not published at all, which is a smaller gap |
| `QUEUE_CONNECTION` | Repointing strands every job already queued elsewhere, silently, while the screen reports success |
| `SANITUBE_API_PREFIX` | It is in every published client URL; changing it breaks every integration at the moment of saving something else |

---

## 6. Provider matrix

| Provider | Adapter | Config | Settings UI | Selectable | Test | Health | Capabilities shown | Can reach CERTIFIED |
|---|---|---|---|---|---|---|---|---|
| Storage — local | yes | yes | yes | yes | yes | yes | — | yes |
| Storage — S3 | yes | yes | yes | yes | yes | yes | — | yes |
| Storage — R2 | yes | yes | yes | yes | yes | yes | — | yes (`EXTERNAL_BLOCKED`: no credentials) |
| Storage — B2 | yes | yes | yes | yes | yes | yes | — | yes |
| AI — OpenAI | yes | yes | yes | yes | **no** | standing only | no | **no writer** |
| AI — Claude | yes | yes | yes | yes | **no** | standing only | no | **no writer** |
| **Transcription — OpenAI** | yes | yes | **no** | **no** | **no** | standing only | no | **no writer** |
| **Artwork — OpenAI** | yes | yes | **no** | **no** | **no** | standing only | no | **no writer** |
| Generation — ACE-Step | yes | yes | selectable only | yes | **no** | via worker | **yes** (studio) | **no writer** |
| Generation — fake | yes | yes | yes | yes | — | — | yes | CODE_READY |
| Distribution — fake | yes | yes | selectable only | yes | **no** | standing only | no | CODE_READY |
| Mail — SMTP | Laravel | yes | yes | — | **yes** | standing | — | yes |
| Worker | yes | yes | yes | — | yes | standing | **partially** | yes |
| DDEX | — | — | — | — | — | hardcoded | — | `EXTERNAL_BLOCKED` |

**The structural finding.** Nine standings, three writers. AI, transcription,
artwork, music generation and distributor display a status that no code path
can ever improve. A `CONFIGURED_UNCERTIFIED` that cannot become `CERTIFIED` is
indistinguishable, to a reader, from one that has failed.

---

## 7. Role / permission matrix

Three roles, three abilities. There is **no dedicated operator, reviewer or
read-only role**, and no way to assign any of them from the interface.

| Ability | OWNER | ADMIN | MEMBER |
|---|---|---|---|
| View catalogue, studio, releases, distribution | yes | yes | yes |
| Write catalogue (`can.role:catalogue`) | yes | yes | **yes** |
| Distribute (`can.role:distribute`) | yes | yes | **no** |
| Administer (`can.role:administer`) | yes | yes | no |
| Manage users | **nobody — no UI** | — | — |
| Manage providers | yes | yes | no |
| View audit | yes | yes | no |
| Run operations (pause, retry, refresh) | yes | yes | no |

**Observations.** OWNER and ADMIN are functionally identical in every route
gate — the distinction exists in the enum and is enforced nowhere, which means
a demotion from OWNER to ADMIN currently changes nothing. MEMBER can write the
catalogue, promote candidates and spend money on generation; the only thing it
cannot do is distribute or administer. There is no read-only role for someone
who should see the catalogue and change nothing.

---

## 8. Operations / commands matrix

35 commands, classified per §11 of the brief.

| Command | Class | UI today | Recommendation |
|---|---|---|---|
| `sanitube:install`, `:provision`, `:deploy`, `:frontend:install` | **D — infra** | none | **Never in UI.** They rewrite the server |
| `sanitube:restore` | **E — destructive** | none | **Never in UI.** Overwrites the live database |
| `sanitube:audit:prune` | E — destructive | none | Never in UI |
| `sanitube:backup` | C — action | read-only status | **Add a guarded Run now** |
| `sanitube:doctor` | D — diagnostic | none | **Add Run diagnostics**, like health refresh |
| `sanitube:smoke`, `:self-test`, `:host` | D — diagnostic | none | Add to System, read-only output |
| `sanitube:providers` | D — diagnostic | **standings shown** | Done (CFG-003) |
| `sanitube:storage:check` | D | partly (`/settings/test`) | Multi-provider form stays CLI |
| `sanitube:worker:check` | D | yes | Done |
| `sanitube:mail:certify` | C | yes | Done (CFG-003) |
| `sanitube:work:pause`/`:resume`/`:status` | C | yes | Done (OPS-002) |
| `sanitube:health`, `:health:refresh` | B — routine | yes | Done |
| `sanitube:assets:cleanup-staging` | B — routine | scheduled | Show last run |
| `sanitube:assets:verify` | C | none | Add trigger + progress; currently unscheduled |
| `sanitube:assets:relocate` | C — **heavy** | none | Add **guarded**, with dry-run first |
| `sanitube:duplicates:evaluate` | C | none | Add trigger; **not** auto-scheduled without a decision |
| `sanitube:media:analyze`, `:fingerprint` | C | none | Add backlog triggers |
| `sanitube:transcription:backlog`, `:enrichment:backlog` | C | none | Add backlog triggers |
| `sanitube:artwork:measure` | C | none | Add backlog trigger |
| `sanitube:production:open-slots`, `:run` | B — deliberately unscheduled | none | Keep manual; surface *last run* |
| `sanitube:import`, `:distribution:export` | C | partly | Import has UI; export is CLI-only |
| `sanitube:user:create` | C | none | **Replace with a Users screen** |
| `sanitube:worker:token` | C | none | Add, copy-once |

---

## 9. Missing screens

| # | Screen | Why | P |
|---|---|---|---|
| 1 | **Users & access** | No way to create, promote, demote or deactivate anybody | **P0** |
| 2 | **Settings › Artwork** | 19 variables, a key, quotas, a circuit breaker — all invisible | **P0** |
| 3 | **Settings › Transcription** | 8 variables and a second OpenAI key — invisible | **P0** |
| 4 | **Production plan create/edit** | The object driving autonomy cannot be made in the product | **P0** |
| 5 | **Backups** | List, sizes, ages, run-now. Today: a count on one card | P1 |
| 6 | **System / About** | Version, commit SHA, migrations, protocol, doctor, readiness | P1 |
| 7 | **Editorial profiles** | Referenced by plans, editable nowhere | P1 |
| 8 | **Artwork generations** | A whole table with statuses, shown nowhere | P1 |
| 9 | **Settings › Duplicates** | Thresholds that decide what is a duplicate | P2 |
| 10 | **Settings › Ingestion** | Batch, concurrency and manifest limits | P2 |

## 10. Missing actions

| Action | Where it belongs | P |
|---|---|---|
| Create / edit / delete a user; set role; deactivate | Users | **P0** |
| Create a production plan; set cadence and target | Production | **P0** |
| Test AI provider connection | Settings › AI | P1 |
| Test artwork / transcription provider | Settings › … | P1 |
| Run backup now (guarded) | Backups | P1 |
| Run doctor | System | P1 |
| Mint worker token | Settings › Workers | P1 |
| Relocate catalogue between providers (guarded, dry-run) | Settings › Storage | P1 |
| Re-analyse / re-fingerprint an asset | Assets | P1 |
| Run duplicate sweep | Duplicates | P1 |
| Bulk confirm/reject duplicates | Duplicates | P2 |
| Export audit log | Audit | P2 |
| Rotate API token | Settings › API | P2 |

## 11. Missing status visibility

| Fact | Available? | Where it should be |
|---|---|---|
| **Storage usage / capacity** | **nowhere** | Dashboard + Settings › Storage |
| **App version / commit SHA** | **nowhere** | System |
| **Frontend build SHA** | installer only | System |
| **Migration status** | CLI only | System |
| **Last successful upload** | nowhere | Dashboard |
| **Last failed import** | batch list only | Dashboard |
| **Last provider error** | nowhere | Dashboard + provider cards |
| **AI / generation spend against quota** | nowhere | Studio + Settings |
| **Worker last heartbeat** | on demand | Dashboard |
| **Worker capabilities** | studio chips only | Settings › Workers |
| **Scheduled task list + last run** | nowhere | Operations |
| Queue backlog, failed jobs | yes | — |
| Scheduler heartbeat | yes | — |
| Backup freshness | yes | — |
| Global pause | yes (OPS-002) | — |

---

## 12. What must stay out of the dashboard

| Thing | Why |
|---|---|
| `APP_KEY` | Replacing it makes every session and every encrypted column unreadable |
| `APP_DEBUG`, `APP_ENV` | Debug on in production puts stack traces, queries and environment values in front of whoever triggers an error. A deployment decision |
| `DB_*` | Repointing a running installation's database is a migration, not a setting |
| `SANITUBE_FFPROBE_PATH`, `SANITUBE_FPCALC_PATH`, `SANITUBE_FFMPEG_PATH` | The platform executes them. A form field here is remote code execution from a stolen session |
| `QUEUE_CONNECTION` | Switching it strands every job already queued, silently |
| `sanitube:restore` | Overwrites the live database. A misclick is unrecoverable |
| `sanitube:install`, `:provision`, `:deploy` | They rewrite the server, its services and its web root |
| `sanitube:audit:prune` | Destroys the record of who did what |
| Trash purge | Permanent deletion of masters. Not built, and should not be until the age, dry-run and reference-blocking rules are decided |
| Backup encryption key | A lost key makes every backup unrecoverable. `REVIEW_REQUIRED` |

---

## 13. Recommended target navigation

```
Dashboard
Studio
  ├ Overview · Projects · Generations · Artwork generations
Library
  ├ Ingest      → Import · Upload · Batches · Candidates
  ├ Catalogue   → Tracks · Artists · Contributors · Compositions
  └ Hygiene     → Assets · Duplicates · Trash · Suggestions
Production
  ├ Plans · Occasions · Editorial profiles
Releases
Distribution
Administration                      (admin only, grouped)
  ├ Users & access
  ├ Operations   → Queue · Jobs · Scheduler · Global pause
  ├ Backups
  ├ Audit
  ├ System       → Version · Health · Doctor · Certifications
  └ Settings     → General · Storage · AI · Transcription · Artwork ·
                   Generation · Distribution · Audio/Media · Workers ·
                   Email · Queue · Production · Duplicates · Ingestion ·
                   Backup · API
```

Changes: Library splits into three sub-groups; administration is grouped rather
than floating; `Rights` and `Analytics` are removed until they exist; the design
system moves behind the admin gate.

---

## 14. Roadmap

**P0 — the product is not usable without these**
1. Users & access screen (create, role, deactivate)
2. Settings › Artwork (19 variables, key, quotas)
3. Settings › Transcription (8 variables, key)
4. Production plan create / edit
5. Storage usage reporting

**P1 — an operator still needs SSH without these**
6. Backups screen with guarded run-now
7. System / About (version, SHA, migrations, doctor)
8. Provider connection tests for AI, artwork, transcription — and the writers
   that let those standings reach CERTIFIED
9. Worker token minting + capability display
10. Editorial profiles screen
11. Artwork generation history
12. Backlog triggers (analyse, fingerprint, transcribe, enrich, dedupe)
13. Guarded catalogue relocation

**P2 — important improvements**
14. Settings › Duplicates, Settings › Ingestion, Settings › General
15. Audit export; API token rotation
16. Bulk duplicate actions (needs the §15 decision first)
17. Per-binary media capability detail

**P3 — polish**
18. Navigation regrouping; drop dead entries; gate the design system
19. Dashboard: last upload, last failed import, last provider error, spend

---

## 15. Dependencies and blockers

| Item | Blocked by |
|---|---|
| R2 certification | Credentials. `R2_CODE_READY=YES`, `R2_CONFIGURED=NO` |
| ACE-Step end-to-end | A GPU host |
| DDEX | No reachable specification — `EXTERNAL_BLOCKED` |
| Distributor certification | A real distributor account |
| Production deployment / smoke | Access to `sanitube.livegine.com` |
| Trash purge | **Your decision**: minimum age, dry-run, package-reference blocking |
| Backup encryption | **Your decision**: `REVIEW_REQUIRED` |
| Scheduled duplicate sweep | **Your decision**: production runtime cost |
| Bulk duplicate actions | **Your decision**: weakens "even exact matches start as proposed" |

## 16. Production gaps

Nothing has been deployed from this environment. `bin/update.sh <ref>` is the
tooling and backs the database up before migrating. `sanitube:smoke` is written
and CI-tested and needs the real URL. The upload ceiling that caused UPL-004 is
now reported by the doctor, but the doctor itself has no UI — so on the live
host that finding still requires SSH.

## 17. UX inconsistencies

1. **Import vs Upload** are two screens for one intention, with different
   limits and different error vocabularies. UPL-004 fixed the ceiling in both;
   the duplication remains.
2. **"Catalog" points at Tracks.** The label promises the catalogue and
   delivers one of its four lists.
3. **Design system is visible to every member.** A developer artefact in a
   business navigation.
4. **Two dead entries** (`Rights`, `Analytics`) render as permanently
   unavailable.
5. **Six standings that cannot change** read as failures rather than as
   missing probes.
6. **No CTA from a failure to its setting** except the Studio one added in
   CFG-002. A distribution or transcription failure names no destination.
7. **Trash has no screen of its own** — it is a filter on the asset list, which
   works but is not discoverable.
8. Translations are complete across all six languages; the parity test enforces
   it. Accessibility beyond labelling and keyboard order remain unaudited and
   are recorded as `NOT_READY` in the readiness ledger.

## 18. Final dashboard blueprint

The target is not more pages. It is that **every capability with a production
caller has exactly one home**, that administration is grouped and gated, and
that no configuration which an operator may legitimately change is reachable
only over SSH — while the four categories in §12 stay firmly out.

---

## Final report

```
CURRENT_DASHBOARD_COMPLETENESS            ≈ 62%
BACKEND_CAPABILITIES_FOUND                  74
BACKEND_CAPABILITIES_WITH_UI                46
BACKEND_CAPABILITIES_WITHOUT_UI             28
CONFIGURABLE_FEATURES_WITHOUT_SETTINGS_UI   88   (of 139 SANITUBE_* variables)
OPERATOR_ACTIONS_REQUIRING_SSH              19   (of 35 artisan commands)
P0_GAPS                                      5
P1_GAPS                                      8
P2_GAPS                                      4
P3_GAPS                                      2
```

### TOP 10 MISSING DASHBOARD FEATURES

1. Users & access — create, promote, demote, deactivate
2. Settings › Artwork — 19 variables including an API key
3. Settings › Transcription — 8 variables including an API key
4. Production plan creation and editing
5. Storage usage and capacity
6. Backups — list, sizes, ages, guarded run-now
7. System / About — version, commit SHA, migrations, doctor
8. Connection tests + certification writers for AI, artwork, transcription
9. Editorial profiles
10. Backlog triggers for analysis, fingerprinting, transcription, enrichment

### RECOMMENDED NEXT IMPLEMENTATION WAVE

**Wave 1 — five tickets, all P0, no external dependency.**

| Ticket | Scope |
|---|---|
| USR-001 | Users & access: screen, create/edit/deactivate, role assignment, audit |
| CFG-006 | Settings › Artwork and Settings › Transcription, with secret handling |
| PROD-002 | Production plan create/edit; editorial profile selection |
| STO-005 | Storage usage measurement + display on dashboard and settings |
| SYS-001 | System / About: version, commit SHA, migrations, doctor with findings |

Wave 1 closes every P0 and removes the two most serious "invisible provider"
gaps. Wave 2 is P1 items 6–13, which is the wave that ends routine SSH.
