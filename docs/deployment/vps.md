# Running SaniTube on a VPS

> **Nothing in this file has been run against a real VPS.** The unit files and
> commands below are written from how these tools work, not from a deployment
> anybody has observed. Every SaniTube-side claim is enforced by a test; the
> *hosting* steps are unverified until somebody runs them and corrects this
> page.

A VPS is not required. SaniTube is designed so a shared cPanel account is
enough — see [cpanel.md](cpanel.md). What a VPS buys you is a persistent queue
worker, which turns an import of several hundred files from an afternoon into
minutes.

## What changes

| | Shared hosting | VPS |
|---|---|---|
| Queue | Short bursts from the scheduler | A persistent worker |
| Scheduler | cPanel cron | systemd timer or crontab |
| Node | Usually absent | Install it and build in place |
| Restarts | Not possible | `systemctl restart` |

Everything else is the same. There is no VPS-only feature, and no code path
that behaves differently.

## Install

```
composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader
npm ci && npm run build
php artisan sanitube:install
```

Point the web server's document root at **`public/`**. With it one level too high,
`.env` is served over HTTP to anyone who asks.

`storage/` and `bootstrap/cache/` must be writable by the web server user.

## The scheduler, the queue and nginx — generated, not copied

Do not hand-copy unit files from a document (this one included). The
installation generates its own, with its real paths, its real PHP and the
account you choose:

```
php artisan sanitube:provision --domain=your.domain --socket=/run/php/php8.3-fpm.sock --user=sanitube --into=/tmp/provision
```

That writes, named after `SANITUBE_INSTANCE_NAME` so two installations on one
VPS cannot collide:

- `<instance>-queue@.service` — a **template** unit. Concurrency is how many
  you enable — `systemctl enable --now sanitube-queue@1 sanitube-queue@2` —
  never a number inside a file. `Restart=on-failure` plus `--max-time=3600`
  is what makes deployment work: `bin/deploy.sh` signals workers to finish
  the job in hand and exit so they pick up new code, and systemd starts them
  again.
- `<instance>-scheduler.service` + `.timer` — every minute, like cron.
  Without a scheduler, retries never retry and distribution status is never
  refreshed — silently. The Operations screen reports a scheduler that has
  never run, which is the only warning you get. On a machine without
  systemd, the generated `crontab.line` is the same tick as a cron entry:

  ```
  * * * * * cd <application> && <php> artisan schedule:run >> /dev/null 2>&1
  ```
- `<instance>.conf` — an nginx server block: `root <app>/public`, everything
  dotted denied, `client_max_body_size` mirroring this PHP's `post_max_size`.
  **HTTP only, on purpose**: run `certbot --nginx` once DNS resolves and it
  adds the TLS half against a real certificate instead of paths we would
  have had to guess.

Installing what was generated is root's job, and validation comes before any
reload:

```
install -m 0644 /tmp/provision/*.service /tmp/provision/*.timer /etc/systemd/system/
systemd-analyze verify /etc/systemd/system/sanitube-*
systemctl daemon-reload
install -m 0644 /tmp/provision/sanitube.conf /etc/nginx/conf.d/
nginx -t && systemctl reload nginx
```

If a file by the same name already exists, look at it before replacing it —
`sanitube:provision` regenerates yours from current facts, but a hand-edited
unit holds somebody's intent.

## Deploying an update

```
bin/deploy.sh
```

See [deploying.md](deploying.md). The script puts the site into maintenance
mode, releases it from a shell trap on any failure, and never pulls a revision
of its own choosing — fetch or upload the code you intend to deploy first.

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

If this installation stores on object storage, there is a third:

```
php artisan sanitube:storage:check --certify
```

**Whether the bucket can do what production asks of it** — not just accept an
object, but promote one server-side, honour a signed URL, and take a presigned
upload. Each of those fails on its own and none of them is visible to a
write/read/delete probe. The procedure, including the one thing no command can
prove, is in [Certifying storage](storage-certification.md).

`docs/production-readiness.md` is the full list, including the parts that need
a real provider or a real host before anybody can honestly call them certified.

## Backups

There is no backup command yet — that is OPS-001, and until it lands you are
responsible for two things:

- **The database.** `mysqldump` on a schedule, stored off the machine.
- **`storage/app`**, if you use local storage. These are the masters. Losing
  them loses the catalogue, and no amount of database backup brings audio back.

Off the machine is the operative part. A backup on the same disk survives a
mistake and not a failure.
