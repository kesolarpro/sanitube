# ADR-0002 — Portability baseline: cPanel is a first-class target

- **Status:** Accepted
- **Date:** 2026-08-14
- **Ticket:** ARCH-001

## Context

SaniTube must be installable on a shared cPanel account, a VPS, a dedicated
server, and on Ubuntu, Debian, AlmaLinux or Rocky Linux — without a rewrite
and without a second codebase.

The tempting reading of "supports cPanel" is *degraded mode*: build for a
modern VPS with Redis, Docker and Supervisor, then bolt on a reduced variant.
That reliably produces a second-class path nobody tests, which breaks quietly
and is discovered by whoever is running on shared hosting.

The constraints a shared account actually imposes:

- No root, no Docker, no systemd, no Supervisor.
- No Redis, and often no persistent process at all.
- One cron entry.
- MariaDB, not MySQL.
- Frequently no FFmpeg, and no way to install one through a package manager.
- PHP 8.2 is a realistic floor; 8.4 is not guaranteed.

## Decision

**The portable configuration is the default configuration.** Everything a
richer environment offers is an optimisation layered on top, never a
prerequisite.

| Concern | Default | Optimisation |
|---|---|---|
| Queue | `database` | `redis` |
| Cache / session | `database` | `redis` |
| Object storage | local disk | S3 / R2 / B2 |
| Audio analysis | absent, reported | FFmpeg |
| Process supervision | one cron entry | Supervisor / systemd |
| Containers | none | Docker, optional |

Consequences of that stance, made concrete:

- **PHP `^8.2`**, which fixes the framework at Laravel 12 (Laravel 13 requires
  a newer PHP than shared hosting reliably provides).
- **`config.platform.php` is pinned to `8.2`.** Without it, Composer resolves
  against whatever PHP the developer happens to run, and a lock built on 8.4
  installs *only* on 8.4 — the floor the project promises stops working
  without anyone touching a version constraint. This was not theoretical: the
  first CI run caught exactly that, with Symfony 8 components requiring PHP
  8.4.1 in a lock file claiming 8.2 support.
- **MariaDB is in the CI matrix**, driven through Laravel's dedicated
  `mariadb` connection alongside MySQL 8.0 and SQLite. cPanel ships MariaDB,
  and the two engines diverge on index key length, `CHECK`, JSON and utf8mb4
  collations. Testing only MySQL would mean the primary target is the one
  never exercised.
- **No hosted-service lock-in in configuration.** Beanstalkd and SQS were
  removed from `config/queue.php`; `laravel/sail` was removed entirely.
- **Missing capability ≠ broken application.** A server without FFmpeg still
  registers assets and manages the catalogue; the feature reports itself
  unavailable with instructions for that environment.

The rules are asserted rather than documented, because documentation does not
fail a build:

| Rule | Enforcement |
|---|---|
| No hard-coded domain | Test scans `src/`, `app/`, `routes/`, `config/` |
| No absolute server paths | Same test, for `/home/<user>/` and `/var/www/` |
| Portable drivers by default | Test reads `.env.example` |
| Lock installs on the minimum PHP | Test asserts the pinned platform and scans locked constraints |
| No PostgreSQL-only SQL | Test scans migrations |
| Schema works everywhere | CI matrix, four engines, with rollback |

## Consequences

**Accepted:**

- One codebase, one tested path, no degraded mode.
- A new install works before any external service is configured.
- The environment is inspected rather than assumed, and every gap comes with
  remediation written for that environment.

**Costs:**

- The default configuration is not the fastest one. A VPS install must be told
  to use Redis; it will not discover it.
- CI is wider — four database engines and three PHP versions — so it is slower
  and costs more minutes than a single-target pipeline.
- Some conveniences are off the table permanently: PostgreSQL-specific types,
  hosted queue services, anything assuming a persistent worker.
- Laravel 12 rather than 13, until PHP 8.3+ is a safe floor on shared hosting.

## Revisit when

- cPanel stops being a deployment target. That single change removes most of
  the constraints above at once.
- Shared hosting standardises on PHP 8.3+, which permits a framework upgrade.
- Never for convenience alone: dropping a portability rule is a product
  decision about who can run the platform, not a technical shortcut.
