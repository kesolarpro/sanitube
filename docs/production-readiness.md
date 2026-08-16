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
| Password reset flow | NOT_READY | Not built. An OWNER resets via `sanitube:user:create` today. |

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
| Real object-storage certification | BLOCKED_EXTERNAL | STO-002. Opt-in suite; no credentials in CI. |

## Queue and scheduler

| Control | Verdict | Notes |
|---|---|---|
| Import and analysis run on the queue | READY | Asserted as *pushed*, never run inline. |
| `sync` refused for production | READY | `sanitube:doctor` blocker. |
| Scheduler heartbeat, staleness visible | READY | Never-run is never reported as healthy. |
| Failed-job visibility | READY | Jobs screen. |
| Failed-job retry / delete from the interface | NOT_READY | SYS-001b. Writes to the queue need their own confirmation surface. |

## Media

| Control | Verdict | Notes |
|---|---|---|
| Analysis optional; absent FFmpeg does not block | READY | The shared-hosting default produces READY, not WAITING_CAPABILITY. |
| Checksums verified on ingest | READY | |
| Duration / loudness when available | READY | |

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
| CSV manifest import | READY | |
| Start an import from the interface | READY | ING-002. |
| Import never creates a Track | READY | The load-bearing negative claim. |
| Re-import storage amplification | NOT_READY | BULK-001c. Needs a measurement, not a guess. |

## Generation

| Control | Verdict | Notes |
|---|---|---|
| Studio, projects, generations | READY | |
| Fake provider drives a full E2E | READY | |
| Generated audio joins the review queue | READY | Never becomes a Track directly. |
| Real generation provider | BLOCKED_EXTERNAL | GEN-002 / AI-002. |

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
| Web installer for shared hosting | NOT_READY | INS-002. Same `InstallationService`; no second implementation. |

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
| Restore path traversal refused | READY | |
| Full external penetration test | BLOCKED_EXTERNAL | Internal audit only. |

## Settings

| Control | Verdict | Notes |
|---|---|---|
| Read what is configured, without values | READY | SET-001. |
| Write configuration from the interface | NOT_READY | SET-002. A security surface; REVIEW_REQUIRED when built. |

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
