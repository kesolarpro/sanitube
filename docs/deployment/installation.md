# Installing SaniTube

```
php artisan sanitube:install
```

That is the whole thing. What follows explains what it does, what it refuses to
do, and what to do when a stage fails.

Not sure what kind of host you are on, or which shape of installation fits it?
Ask the machine first:

```
php artisan sanitube:host
```

It reads the host — never executing anything — and suggests one of five
installation profiles: `CPANEL`, `VPS_CORE`, `VPS_CORE_AND_WORKER`,
`WORKER_ONLY`, `CORE_ONLY_GENERIC`. The suggestion comes with its reasons and
its cautions, and `--json` produces the same report for tooling. Only the three
detectable profiles are ever suggested; whether a VPS should also carry the
worker is what you want, not what the machine is, so those two are always
offered as choices instead.

## What it needs

- **PHP 8.2 or newer** with the extensions in `.github/workflows/ci.yml`.
- **A database** — MySQL 8, MariaDB 10.6 or 11.4, or SQLite.
- **A writable `storage/` and `bootstrap/cache/`.**

What it does **not** need, and will never ask for: root, Docker, Redis,
Supervisor, or a persistent queue worker. A shared cPanel account is the
baseline target, and everything below works there.

## The stages

The installer runs seven stages in order and reports each one as it finishes.

| Stage | What it does | When it skips |
| --- | --- | --- |
| `preflight` | Runs the capability detectors | never |
| `environment` | Copies `.env.example` to `.env` | a `.env` already exists |
| `application_key` | Generates `APP_KEY` | a key is already set |
| `database` | Opens a connection | never |
| `migrations` | `migrate --force` | never (migrating twice is a no-op) |
| `owner` | Creates the first OWNER | an active owner exists |
| `verify` | Asks the installation whether it is installed | never |

**Every stage is idempotent.** Running the installer a second time after a
failure picks up where it stopped rather than starting over — which is what you
will want to do, because a failure stops the run and leaves everything before
it in place.

There is no rollback. Undoing a successful migration because a later stage
failed would turn a recoverable situation into a lost database.

## What it will not do

**It will not overwrite your `.env`.** If one exists the stage is skipped and
the file is untouched.

**It will not replace an existing `APP_KEY`.** That key decrypts existing
sessions and any encrypted column; replacing it on a running installation logs
everyone out and makes previously encrypted data unreadable.

**It backs up `.env` before modifying it**, once per run, as
`.env.backup-<timestamp>` with mode `0600`. That file holds every credential
the original does — it is git-ignored, and on a shared host you should delete
it once you are satisfied.

**It will not print a secret.** Stages name the key they set, never its value.

**It will not accept a password as a command-line option.** Options land in
shell history and in every process listing on the machine. The password is
prompted for and never echoed.

## Unattended installs

```
php artisan sanitube:install --no-interaction --skip-owner
php artisan sanitube:user:create --name="Label" --email=owner@example.test --role=OWNER
```

A password cannot be collected without a prompt, so an unattended run must skip
the owner and create the account separately — `sanitube:user:create` prompts
for the password secretly.

With `--skip-owner`, `verify` reports success once everything except the owner
is in place, so a deployment script gets a usable exit code. It says plainly
that the account still has to be created.

## When a stage fails

**`preflight`** names the blocking capabilities. `php artisan sanitube:health`
prints the same report with the remediation for each.

Two capabilities deliberately do *not* block an install: the **scheduler**,
which cannot have a heartbeat before cron has run once, and the **database**,
which has its own stage with a better message. Both are checked afterwards.

**`database`** almost always means the `DB_` settings. On shared hosting the
host is usually `localhost` and both the database name and the user name carry
an account prefix (`myaccount_sanitube`, `myaccount_dbuser`).

**`migrations`** — run `php artisan migrate` directly to see the error.

## After installing

Two things the installer cannot do for you, because both live outside PHP.

**The scheduler.** Add one cron entry:

```
* * * * * php /home/youraccount/sanitube/artisan schedule:run >> /dev/null 2>&1
```

Without it, retries, health refreshes and scheduled imports never happen — and
nothing anywhere says so except the operations screen, which reports the
missing heartbeat.

**The queue.** SaniTube's default is the **database** queue, which needs no
Redis and no Supervisor. On a host that allows a long-running process:

```
php artisan queue:work --sleep=3 --tries=3
```

On shared hosting where it does not, add a second cron entry:

```
* * * * * php /home/youraccount/sanitube/artisan queue:work --stop-when-empty --max-time=55 >> /dev/null 2>&1
```

`--stop-when-empty` and `--max-time` keep each run inside the minute, so the
next cron tick starts a fresh worker rather than piling up.

**Point the document root at `public/`**, never at the project root. Everything
above `public/` — including `.env` — is readable over HTTP if you do not.

## Verifying

```
php artisan sanitube:health
```

Or open **Operations** in the interface (owner or admin), which reads the last
stored health report and says how old it is.
