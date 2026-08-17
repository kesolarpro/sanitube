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
it.

### Option A — repoint the document root (do this if you can)

```
/home/USER/sanitube/          <- the application, private
/home/USER/sanitube/public/   <- document root
```

Everything private stays private because the web server never sees it. Nothing
else in this document changes.

cPanel lets you set this for **addon domains and subdomains** freely. Use a
subdomain if the primary domain's root is fixed — it is the cheapest way to
land in Option A.

### Option B — the root is fixed (primary domains, some hosts)

Some accounts cannot repoint the primary domain: `public_html` is the document
root and that is that. **This is still safe, and it does not require moving
Laravel into `public_html`.**

```
/home/USER/sanitube/          <- the whole application, private, untouched
/home/USER/public_html/       <- document root: two files and one link
```

Put **only these** in `public_html`:

1. everything from `sanitube/public/` **except** `index.php` — that is
   `.htaccess`, `favicon.ico`, `robots.txt` and the built `build/` directory;
2. an `index.php` that boots the application from outside the root;
3. nothing else, ever.

The front controller is Laravel's own with two paths redirected:

```php
<?php
// /home/USER/public_html/index.php
$app = '/home/USER/sanitube';                 // absolute, and outside this directory

require $app.'/vendor/autoload.php';

$application = require_once $app.'/bootstrap/app.php';

$application->handleRequest(Illuminate\Http\Request::capture());
```

**Why this is safe and "just move Laravel in" is not.** The only things reachable
over HTTP are the three static files, the compiled front-end bundle, and a PHP
file that reads code from a directory the web server cannot serve. `.env`,
`vendor/`, `storage/`, `config/`, `database/`, `src/`, `app/`, `composer.json`
and `.git` are all outside the document root and stay there. Moving the
application into `public_html` puts every one of them one URL away, and no
amount of `.htaccess` makes that safe: a rule that stops serving `.env` is a
rule one server misconfiguration, one `AllowOverride None`, or one nginx
front-end away from not applying.

### What Option B costs

- **`php artisan storage:link` produces the wrong link.** It links
  `sanitube/public/storage`, which is no longer served. Either create the link
  in `public_html` by hand, or — better — keep private assets on remote storage
  and serve previews through the signed-URL path SaniTube already uses.
- **Deployments touch two directories.** After `npm run build` you must copy
  `public/build` into `public_html/build` as well. `sanitube:deploy` does not do
  this for you; do it in the same step, and treat a stale bundle as the first
  thing to check when a screen looks wrong after an update.
- **`APP_URL` must still be the real HTTPS URL.** Nothing about Option B changes
  that, and getting it wrong breaks signed URLs.

### What Option B does not permit

If your host also forbids PHP from reading outside the document root — some
shared plans set `open_basedir` to `public_html` alone — then Option B cannot
work either, and neither can any safe arrangement. **Do not install SaniTube on
that host.** Check before you start:

```
php -r 'echo ini_get("open_basedir") ?: "unrestricted", PHP_EOL;'
```

An empty result, or one that includes `/home/USER`, is fine. One limited to
`/home/USER/public_html` is a refusal.

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

## Before you call it live

Two commands, and they answer different questions.

```
php artisan sanitube:health
```

**What this machine can do** — PHP extensions, FFmpeg, Redis, object storage.
The same question in development as in production, and a missing optional
capability is reported rather than fatal.

```
php artisan sanitube:doctor
```

**Whether this installation is configured the way a live one has to be.** Most
of what it checks is correct on a laptop and serious here: debug mode left on,
`APP_URL` still pointing at localhost, a queue that runs work inline, backups
written somewhere the web server will serve them.

It is read-only — it starts nothing and changes nothing — and it **exits
non-zero when something internal blocks going live**, so a deploy script can
gate on it:

```
php artisan sanitube:deploy && php artisan sanitube:doctor || echo "not ready"
```

Neither command prints a secret. Both are safe to paste into a support thread.

`docs/production-readiness.md` is the full list, including the parts that need
a real provider or a real host before anybody can honestly call them certified.

**Prove the private code is private.** From anywhere, against the real URL —
every one of these must be `403` or `404`, and none of them `200`:

```
curl -o /dev/null -s -w '%{http_code} %{url_effective}\n' \
  https://YOUR-DOMAIN/.env \
  https://YOUR-DOMAIN/composer.json \
  https://YOUR-DOMAIN/storage/logs/laravel.log \
  https://YOUR-DOMAIN/vendor/autoload.php \
  https://YOUR-DOMAIN/.git/config \
  https://YOUR-DOMAIN/artisan
```

A `200` on any line means stop and rotate every credential in `.env` before
doing anything else. This check is worth running again after each deployment:
it costs a second and it is the only one that catches a document root that
moved back.

## Things that will go wrong

| Symptom | Cause |
|---|---|
| `.env` downloadable over HTTP | The document root is the application directory rather than `public/` (Option A), or files were copied into `public_html` beyond the three named ones (Option B). **Fix immediately and rotate every credential in it** — assume it was read. |
| Blank page under Option B, nothing in the log | `open_basedir` is confining PHP to `public_html`, so `require` cannot reach the application. See "What Option B does not permit". |
| Screens look wrong after an update, under Option B | `public_html/build` still holds the previous bundle. Copy `public/build` across. |
| Nothing ever finishes importing | No cron entry, or it runs the wrong PHP binary. |
| A credential edit changes nothing | The configuration cache is built. `php artisan config:clear`, or run `bin/deploy.sh`. |
| 500 with no detail | Check `storage/logs`. Do **not** turn on `APP_DEBUG` to investigate — it shows stack traces, queries and environment values to whoever triggers the error. |
| Public files 404 | The host forbade the symlink. Serve `storage/app/public` another way, or use remote storage. |
