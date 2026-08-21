# Deploying SaniTube

One codebase, three legitimate shapes — a shared cPanel account, a VPS, a
bare Core with everything manual — and the same rule everywhere: the machine
is read, never assumed.

## Quick start, VPS

```
git clone <your-repo> /srv/sanitube && cd /srv/sanitube
bin/bootstrap.sh                       # reads the machine; prints what is missing
bin/bootstrap.sh --yes-install-packages   # or lets it install PHP/Composer, verified
php artisan sanitube:install --profile=VPS_CORE --config=/root/sanitube-install.conf
php artisan sanitube:provision --domain=your.domain --socket=/run/php/php8.3-fpm.sock --user=sanitube --into=/tmp/provision
# install the generated units and the nginx block as root — provision prints the commands
php artisan sanitube:frontend:install /path/to/sanitube-frontend-build.zip --sha=<commit>
php artisan sanitube:doctor && php artisan sanitube:smoke
```

Details: [vps.md](vps.md), [installation.md](installation.md).

## Quick start, cPanel

Upload or clone the application outside `public_html`, point the document
root at `<application>/public` (or use the front-controller strategy in
[cpanel.md](cpanel.md)), then either open `/install` in a browser — the web
installer walks the same stages — or run `php artisan sanitube:install
--profile=CPANEL` from the cPanel terminal. The completion message is the
cron line to paste. The frontend arrives prebuilt: [deploying.md](deploying.md).

## The pieces, and which page owns each

| Concern | Command | Page |
|---|---|---|
| What machine is this? | `sanitube:host` | [installation.md](installation.md) |
| Install / resume / status | `sanitube:install`, `--config`, `--status` | [installation.md](installation.md) |
| Services: cron, systemd, nginx | `sanitube:provision` | [vps.md](vps.md) |
| Frontend without Node | `sanitube:frontend:install` | [deploying.md](deploying.md) |
| Update to a named revision | `bin/update.sh <ref>` | [deploying.md](deploying.md) |
| Fitness to be live | `sanitube:doctor` | [deploying.md](deploying.md) |
| Real HTTP proof | `sanitube:smoke` | [deploying.md](deploying.md) |
| Provider standings | `sanitube:providers` | [storage-certification.md](storage-certification.md), [worker-certification.md](worker-certification.md) |
| Backups and restore | `sanitube:backup`, `sanitube:restore` | [backup.md](backup.md) |
| A worker host | `sanitube:worker:token`, `sanitube:worker:check` | [worker.md](worker.md) |

## What no page will tell you to do

Run `composer update` on a server. Deploy "whatever main is now". Put a
password on a command line. Copy a unit file out of documentation instead of
generating it. Pipe a download into a shell. Skip the backup because the
migration looks small.
