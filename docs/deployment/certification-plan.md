# The real-world certification plan

> Everything in this file is runnable with the shipped code — no development
> edits, no branches. Until somebody executes it on a real host, the
> deployment automation is CODE_READY and says so; running this plan is what
> turns rows in `sanitube:providers` and the readiness ledger into CERTIFIED.

Two reference installations certify the product: the existing cPanel
installation (already live, already the reference for that profile) and one
fresh VPS. This is the VPS script. Every manual intervention discovered
along the way gets written down, classified, automated where safely
possible, and turned into a test — that is part of the mission, not a
footnote to it.

## Prerequisites (the operator brings these)

- A fresh supported VPS — Ubuntu LTS or Debian stable recommended for the
  first reference; the architecture is distro-independent and the bootstrap
  is CI-tested on Ubuntu, Debian and AlmaLinux. 2 vCPU / 2 GB RAM / 40 GB
  disk is comfortable for Core; the GPU worker is sized separately.
- A domain with DNS already pointing at the VPS.
- A database decision: local MariaDB/MySQL installed by the operator, or an
  external one with credentials.
- An install config file, `chmod 600` (see installation.md).

## A. Fresh Core install

```
git clone <repo> /srv/sanitube && cd /srv/sanitube
bin/bootstrap.sh --yes-install-packages
php artisan sanitube:host                       # expect VPS_CORE suggested
php artisan sanitube:install --profile=VPS_CORE --config=/root/sanitube-install.conf
php artisan sanitube:provision --domain=<domain> --socket=<fpm socket> --user=<svc user> --into=/tmp/provision
# install units + nginx block as root; provision prints the exact commands
php artisan sanitube:frontend:install <artifact.zip> --sha=$(git rev-parse HEAD)
php artisan sanitube:self-test
```

Pass: doctor green, smoke green (forbidden paths refused, asset serves,
HTTPS after certbot), scheduler heartbeat visible within two minutes,
queue certification job consumed.

## B. Reboot resilience

`reboot`, wait, then `php artisan sanitube:self-test` again. Pass: web
server, PHP-FPM, database (if local), queue units, scheduler timer all came
back on their own; smoke green. This is what the systemd units exist for —
an installation is not VPS-certified before this test.

## C. Upgrade

`bin/update.sh <same or newer ref> --frontend=<matching artifact>` — twice.
Pass: idempotent (no duplicate cron, no duplicate units, APP_KEY intact,
owner intact, frontend intact), doctor and smoke green after each run.

## D. Rollback (code)

`bin/update.sh <previous ref> --frontend=<its artifact>`. Pass: the site
serves the previous version; schema stays forward (no down migrations —
forward-fix is the policy, restore is a disaster operation).

## E. Backup

Confirm the scheduled backup ran (`storage/backups`, doctor freshness
check). Pass: manifest present, doctor reports fresh.

## F. Restore verification

On a scratch database (never the live one):
`php artisan sanitube:restore <backup> --database=<scratch>` per backup.md.
Pass: row counts match, the restored owner can log in against the scratch
schema.

## G. R2 certification

Configure R2 credentials, then `php artisan sanitube:storage:check --certify`.
Pass: every check passes against the real bucket and `sanitube:providers`
reports object_storage CERTIFIED. Then browser CORS: one real direct upload
from the import screen — server-side checks cannot certify CORS.

## H. Worker certification

On the worker host: install per worker.md, mint the token
(`sanitube:worker:token`), start the service. On Core:
`php artisan sanitube:worker:check`. Pass: handshake, protocol, capability
vocabulary, refusals, token all green; `sanitube:providers` reports worker
CERTIFIED.

## I. One real media analysis

Import one real audio file end to end (`sanitube:import` or the browser
path). Pass: analysed, fingerprinted, candidate ready — through the worker
if configured, locally otherwise. This is the check `worker:check`
deliberately does not make: that the worker can reach storage and finish
real work.

## J. Optional generation certification

Only with compatible hardware and an explicitly configured provider or
ACE-Step worker; commercial/model rights remain a separate question. Pass:
one generation reaches the studio screen with provenance recorded.

## K. Security smoke

`php artisan sanitube:smoke` covers the forbidden paths; additionally
confirm HTTPS redirects/certificates by hand and that the worker's port is
not publicly reachable except where intended.

## What is deliberately not in this plan

Automatic down-migrations (forward-fix policy), backup encryption at rest
(REVIEW_REQUIRED, owner's decision), DDEX (BLOCKED_EXTERNAL), and any step
that would require editing the application to pass — if one appears, that
is a bug in the automation and goes back to a ticket.
