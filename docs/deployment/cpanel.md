# Running SaniTube on cPanel shared hosting

> **Nothing in this file has been run against a real cPanel account.** It is
> written from the constraints cPanel imposes, which are stable and
> well-documented, and every SaniTube-side claim in it is enforced by a test.
> The *hosting* steps are unverified until somebody deploys onto a real
> account and corrects this page. Treat a step that does not match your
> control panel as this document being wrong, not you.

Shared hosting is the baseline target, not an afterthought. A label with a back
catalogue and a £5 hosting plan is the person this platform is for, and every
design decision that looks over-careful elsewhere — no Redis, no Supervisor, no
Docker, no root — is here because of them.

## What cPanel gives you, and what it does not

| | |
|---|---|
| **PHP version** | Selectable per domain. SaniTube needs **8.2 or newer**. |
| **Composer** | Usually present. If not, run `composer install` elsewhere and upload `vendor/`. |
| **Node / npm** | Usually **absent**. Build assets elsewhere and upload `public/build`. |
| **Cron** | Yes, through the panel. This is how the scheduler runs. |
| **Long-running processes** | **No.** No Supervisor, no systemd, no persistent queue worker. |
| **Root** | **No.** Nothing in SaniTube asks for it. |
| **Symlinks** | Sometimes forbidden. `sanitube:deploy` treats that as a note, not a failure. |

## Document root

Point the domain at **`public/`**, not at the application directory.

This is the single most consequential setting on the page. With the document
root one level too high, `.env` — your database password, your storage
credentials, your application key — is served over HTTP to anyone who asks for
it. If your host will not let you set the document root, SaniTube should not be
installed on that host.

## Install

1. Upload the application, or clone it, outside `public_html`.
2. Point the domain's document root at the application's `public/` directory.
3. Set the PHP version to 8.2 or newer.
4. Create a MySQL database and a user with full rights on it.
5. From SSH or cPanel's terminal:

```
composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader
php artisan sanitube:install
```

The installer asks for the database details and the first owner. It is
idempotent — a failure at stage five leaves the first four in place, and
re-running picks up rather than starting over.

If there is no terminal at all, `php artisan sanitube:install --no-interaction`
can be driven from a cron entry set to run once, with the owner supplied by
`--owner-name` and `--owner-email`.

## The scheduler

**This is the step people skip, and skipping it is silent.** Without it,
retries never retry, distribution status is never refreshed, and the health
snapshot goes stale — and nothing anywhere says so. The Operations screen
reports the scheduler as never having run, which is the only warning you get.

Add one cron entry, running every minute:

```
* * * * * /usr/local/bin/php /home/USER/sanitube/artisan schedule:run >> /dev/null 2>&1
```

Use the PHP binary for the version you selected — cPanel's default `php` on the
command line is often an older one than the domain uses. `which php82` or the
panel's "PHP version" page will tell you which.

## Queue work without a queue worker

There is no persistent worker on shared hosting. SaniTube's scheduler runs the
queue in short bursts instead, so imports, analysis and generation all progress
without one — more slowly than a dedicated worker, and correctly.

An import of several hundred files will take a while. That is the trade, and it
is why `sanitube:import` queues rather than importing inline: a browser tab
closing, or a PHP process being killed at the host's time limit, must not lose
the batch.

## Deploying an update

```
bin/deploy.sh
```

If npm is missing the script says so and carries on — build `public/build`
elsewhere and upload it. See [deploying.md](deploying.md) for what each stage
does and why.

`bin/deploy.sh` never runs `git pull`: upload or pull the new code yourself,
then run it.

## Storage

The default is local storage under `storage/app`. That is fine, and it is
covered by your host's backups only if your host backs up your account — check,
because "the host has backups" is a belief people discover is wrong at the
worst possible moment.

S3, R2 or B2 work from shared hosting with no special support. Configure them
and the Settings screen will show the credentials as present.

## Things that will go wrong

| Symptom | Cause |
|---|---|
| `.env` downloadable over HTTP | Document root is not `public/`. Fix immediately and rotate every credential in it. |
| Nothing ever finishes importing | No cron entry, or it runs the wrong PHP binary. |
| A credential edit changes nothing | The configuration cache is built. `php artisan config:clear`, or run `bin/deploy.sh`. |
| 500 with no detail | Check `storage/logs`. Do **not** turn on `APP_DEBUG` to investigate — it shows stack traces, queries and environment values to whoever triggers the error. |
| Public files 404 | The host forbade the symlink. Serve `storage/app/public` another way, or use remote storage. |
