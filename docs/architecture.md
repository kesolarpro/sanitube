# SaniTube — Architecture

Status: foundation (ARCH-001). This document describes what exists today and
the boundaries the rest of the platform will be built inside. It is updated
with every architectural ticket, not at the end.

## 1. The one rule

**The SaniTube catalogue is the source of truth.**

Too Lost, TuneCore, LabelGrid, Suno, OpenAI, Claude, Cloudflare, Amazon and
Backblaze are *suppliers*. Every one of them sits behind an interface owned by
SaniTube, and none of their vocabulary, identifiers or DTOs is allowed past
that boundary. Concretely:

- A provider outage degrades one screen, never the catalogue.
- A provider can be replaced by writing an adapter and changing a config value.
- A missing API key is a normal state, not an error state.

The corollary matters just as much: **no module may depend on a provider being
available in order to be built or tested.** That is why the music generation
and AI modules ship with a fake and a null implementation from day one.

## 2. Stack

| Concern | Choice | Why |
|---|---|---|
| Framework | Laravel 12 | Requires PHP ^8.2, which is the floor cPanel accounts can meet. Laravel 13 requires a newer PHP than shared hosting reliably offers. |
| PHP | 8.2 minimum, 8.3+ recommended | 8.2 is what shared hosting has; the health check reports anything older as blocking and 8.2 as supported-but-dated. |
| Database | MySQL / MariaDB | The only engines shared hosting offers. No PostgreSQL-specific feature is used anywhere, and a test enforces it. |
| Queue | `database` by default, `redis` optional | A shared account has no Redis and no persistent worker. |
| Cache / session | `database` by default | Same reason. |
| Object storage | S3-compatible, local supported | Local keeps a single-server install fully functional. |
| Audio | FFmpeg, optional | Absent on most shared hosting; its absence disables analysis, not the platform. |
| Frontend | Vite (Vue 3 + Inertia + Tailwind arrives with the dashboard ticket) | — |

## 3. Modular monolith

One deployable application, explicit module boundaries, no microservices.

```
src/
├── Foundation/     shared kernel — the module system itself
├── Localization/   UI locales, content languages
├── Storage/        object storage abstraction
├── Observability/  capability detection, health, scheduler heartbeat
├── Api/            REST API v1 surface
├── MusicGeneration/  generation provider contract + fake
├── AI/             LLM provider contract + null provider
└── Distribution/   distributor contract + null provider
```

Modules are declared in `config/sanitube.php`. Declaring a module that has no
directory yet is free — the loader only wires up what exists. Each module may
optionally expose:

| Path | Loaded as |
|---|---|
| `<Module>ServiceProvider.php` | registered service provider |
| `Database/Migrations/` | migrations |
| `Resources/lang/` | translations, namespaced by module key |
| `Resources/views/` | views, namespaced by module key |
| `Routes/web.php` | `web` middleware group |
| `Routes/api.php` | `api` middleware group, under the configured API prefix |
| `Routes/console.php` | console routes |

`Foundation` is the shared kernel, not a bounded context: it holds the module
system and nothing domain-specific. A test asserts that every directory in
`src/` other than `Foundation` is declared in configuration, so a module can
never be created and silently left unwired.

### Extraction later

Because modules communicate through published contracts rather than by
reaching into each other's classes, a module can be lifted out into its own
service if it ever needs to be. Nothing is designed *for* that today — the
point is only that the option is not foreclosed.

## 4. Internationalisation

Two different things that are never conflated:

- **UI locale** — the language the dashboard is rendered in. Configured in
  `config/localization.php`, negotiated per request.
- **Content language** — the language of a track, a release title or a set of
  lyrics. Catalogue data. Modelled by `SaniTube\Localization\ContentLanguage`.

Shipping locales: `en`, `fr`, `es`, `it`, `pt`, `de`. Adding Arabic, Japanese,
Korean, Hindi or Chinese means appending a config entry and a `lang/<code>`
directory — no class changes. `direction: rtl` is already honoured, so
right-to-left languages do not require a redesign.

Content languages use ISO 639-1, plus the three ISO 639-2 codes that already
mean exactly what the platform needs:

| Code | Meaning |
|---|---|
| `zxx` | no linguistic content — instrumental |
| `und` | undetermined — not yet classified |
| `mul` | multiple languages |

No invented values. Regional variants reduce to the language (`pt-BR` → `pt`):
a recording has a language, not a market.

A test asserts that every configured locale has a translation file and that no
key present in English is missing from any other locale.

## 5. Portability

The rules, and how each is enforced:

| Rule | Enforcement |
|---|---|
| No hard-coded domain | Test scans `src/`, `app/`, `routes/`, `config/` for absolute URLs |
| No absolute server paths | Same test scans for `/home/<user>/`, `/var/www/` |
| No mandatory Redis | `.env.example` defaults asserted to be `database` |
| No mandatory Docker | No Dockerfile is required to run or deploy; `laravel/sail` was removed |
| No PostgreSQL-specific SQL | Test scans migrations for `jsonb`, `tsvector`, `ARRAY[]` |
| No root required at runtime | Nothing in the application writes outside the project directory |

Beanstalkd and SQS were removed from `config/queue.php`: supporting a hosted
queue service would tie an install to a cloud vendor for no gain over
`database` and `redis`.

## 6. Capability detection

The platform never assumes what a server can do — it looks, and it explains.

`php artisan sanitube:health` runs every detector in `config/capabilities.php`
and prints what is available, degraded, optional or missing, each with a
remediation line written for the environment it applies to (cPanel vs
apt vs dnf).

Four states, and the distinction is the point:

- **available** — present and usable.
- **degraded** — works, in a reduced mode. A database queue instead of Redis.
- **optional** — absent by choice; nothing is broken. Missing FFmpeg.
- **unavailable** — required and missing. Only this blocks an install.

Two guarantees hold even when the environment is badly broken:

1. A detector that throws is reported as a failed capability rather than
   taking the whole report down.
2. A capability whose backing service is unreachable does not masquerade as a
   detector bug — a dead database makes the scheduler heartbeat unreadable,
   and the scheduler reports "could not be read" while the database detector
   reports the actual cause.

### Scheduler heartbeat

A cron entry that was never created is invisible until royalties stop
importing weeks later. A once-a-minute scheduled task writes a heartbeat, and
the detector reports it as never-run, stale (>15 min) or healthy.

## 7. HTTP surface

`/up` — framework liveness probe, public.

`/api/v1/health` — liveness, public, discloses nothing.

`/api/v1/health/ready` — readiness. 503 while any required capability is
missing, so a deployment can hold traffic back instead of serving errors.

`/api/v1/system/capabilities` — the full report backing the System screen.

The last two describe the environment in detail, so they are protected by a
shared token (`SANITUBE_HEALTH_TOKEN`) and **return 404 until that token is
set**. A fresh install therefore cannot leak its configuration by accident.
This is a stopgap: they move behind real authentication when the Identity
module lands.

The API is versioned from the first commit. `/api/v2` will be added as a new
route file, never by editing v1.

## 8. Provider contracts

Three contracts are fixed now because everything downstream is written against
them:

- `SaniTube\Storage\Contracts\StorageProvider` — complete. Implementations for
  local, S3, Cloudflare R2 and Backblaze B2.
- `SaniTube\MusicGeneration\Contracts\MusicGenerationProvider` — provisional.
  Models generation as asynchronous, with a working fake so the Studio,
  campaigns and ingestion can be built with no external music API.
- `SaniTube\AI\Contracts\AiProvider` — provisional, with a null provider so a
  fresh install has working AI *plumbing* and no AI features.
- `SaniTube\Distribution\Contracts\Distributor` — identity and read side only.

### Why the distributor contract is partial

The write side — `createRelease`, `uploadAudio`, `uploadArtwork`,
`validateRelease`, `submitRelease`, `requestTakedown` — is deliberately **not**
declared yet. Those methods take a release, and the Release aggregate does not
exist until REL-001. Declaring them now would mean inventing payload types,
and the first real adapter would then either bend the domain to fit the guess
or force the interface to be rewritten. What is fixed instead is the part that
does not depend on the domain model: identity, availability, sandbox-vs-
production, and a normalised `DeliveryStatus`. That is already enough to build
delivery tracking and the distribution screens against, and DIST-001 completes
the contract against a real API rather than an imagined one.

`DeliveryStatus` is SaniTube's own vocabulary. Each adapter normalises its
provider's wording into it; no distributor's status string reaches the
catalogue.

## 9. Known limitations

- **Larastan is not declared in `composer.json`.** The development environment
  this project is built in cannot download it — GitHub archive endpoints
  return 403 under the sandbox's egress policy, and every `composer install`
  would fail with the dependency declared. CI installs it explicitly in the
  static-analysis job, and `phpstan.neon.dist` is in the repository. To run it
  locally: `composer require --dev larastan/larastan:^3.0 && composer analyse`.
- **`league/flysystem-aws-s3-v3` is not a dependency.** S3, R2 and B2 need it;
  installs that never leave the local disk should not carry the AWS SDK. The
  object-storage detector says so explicitly when a cloud provider is selected
  without it.
- **No domain model yet.** Artists, tracks, compositions, releases, rights and
  royalties arrive in ARCH-002 and the CAT/REL/RGT tickets.
- **No frontend yet.** Vite is configured; the dashboard is its own ticket.
