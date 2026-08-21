# Deploying SaniTube

Deploying updates a **running** installation. Installing sets one up. They look
alike and they are not: an install may create a database, an application key
and an owner, and a deploy must create none of those. If they are missing, you
are running the wrong command — see [installation.md](installation.md).

```
bin/deploy.sh --dry-run     # report what would happen, change nothing
bin/deploy.sh               # deploy
```

## Updating to a named revision

```
bin/update.sh v1.4.2 --frontend=/path/to/sanitube-frontend-build.zip
```

The revision is required — a tag, a branch, or a SHA — and there is no
default on purpose: an update that assumed "whatever main is now" would
deploy somebody else's afternoon. The script refuses a dirty tree, backs the
database up *before* maintenance mode, checks the revision out, installs
dependencies, installs the frontend artifact tied to that revision, runs
`sanitube:deploy` (which holds the deploy lock, so two updates cannot
interleave migrations), and gives the doctor the last word after the site is
back up. On success it prints the previous SHA — going back is
`bin/update.sh <previous>`, because schema changes do not reverse; restoring
the backup is a separate, deliberate act.

## The two halves, and why they are two

`bin/deploy.sh` does what a shell has to do: `composer install`, `npm ci`,
`npm run build`. `php artisan sanitube:deploy` does the framework steps:
migrate, rebuild caches, storage link, restart workers.

The split is not tidiness. **PHP cannot safely rewrite its own autoloader
mid-request.** A deploy command that ran `composer install` on itself would
finish the run half on the old code and half on the new, and the failures that
produces are among the strangest you will ever read.

## What the script deliberately will not do

- **It never runs `git pull`.** Which revision is deployed belongs to whoever
  is deploying — a CI runner, a hook, a person at a terminal. A script that
  decides is a script that deploys the wrong branch one day.
- **It never creates a database, a `.env` or an owner.** Being asked to deploy
  onto a machine with none of them means the wrong command was run, and
  inventing them turns that mistake into a second, empty installation nobody
  notices.
- **It never drops, rebuilds or seeds the database.** There is a live catalogue
  on the other side.

A test reads the script as text and enforces the last two.

## Stages

| Stage | What it does | Can it fail the deploy? |
|---|---|---|
| preflight | Checks `.env`, the application key and the database are already there | **yes** — nothing after it runs |
| pending migrations | Reports whether anything is waiting | yes |
| migrate | `migrate --force`. Never `--seed`, never fresh | yes |
| caches | Clears then rebuilds config, routes and views | yes |
| storage link | Creates `public/storage` if it can | no |
| queue restart | Signals workers to pick up the new code | no |

The last two cannot fail a release, and that is deliberate: a host that forbids
symlinks and an installation with no queue worker are both legitimate. Failing
a deploy over either would teach people to ignore the exit code.

**Caches are cleared before being rebuilt.** A stale cached config on a machine
whose `.env` has just changed is the failure mode where somebody edits a
credential, restarts, and nothing happens. If the rebuild fails after the
clear, the installation is left *uncached* rather than wrongly cached — slower,
and correct, which is the right way round to fail.

**Migrations run before caches.** A config cache rebuilt before a migration
that adds a setting is a cache that has to be rebuilt again, and nobody does it
twice.

## Queue workers

`queue:restart` asks workers to finish the job in hand and exit. **It does not
start them again** — that is your supervisor's job (systemd, supervisord, a
cPanel cron). If nothing restarts them, the queue silently stops.

Without this step a deploy leaves workers running the *previous* version until
somebody happens to restart them, and the symptom is a job behaving like last
week's code — among the hardest things there is to diagnose.

## Maintenance mode

The script puts the site into maintenance mode first and releases it from a
shell trap, so a failure at any later step still brings the site back up. If
the process is killed outright (`kill -9`, an OOM killer), the trap does not
run and the site stays down: `php artisan up`.

## Shared hosting without Node

`npm` is optional, and on production it should stay absent. CI publishes the
built bundle as the `sanitube-frontend-build` artifact on every green run —
the exact contents of `public/build` for that commit. Download it (from the
run's page, or `gh run download` on a machine with your credentials), get it
to the server however files get to that server, and install it atomically:

```
php artisan sanitube:frontend:install /path/to/sanitube-frontend-build.zip --sha=<commit>
```

`--sha` ties the bundle to the code: hashed filenames only match the templates
of the commit that produced them, so a mismatch against this checkout's HEAD
is refused with both commits named. On a host that is not a git checkout (a
cPanel upload), the sha is recorded instead of checked. The swap is two
renames — a request during install sees the old build or the new one, never
half — and the archive is treated as hostile until proven boring: entries are
streamed to paths the installer chose, and traversal, absolute names or
symlinks refuse with nothing installed.

The command deliberately downloads nothing itself: a GitHub token with API
scope living in a server's environment for the sake of a deploy step is a
bigger door than the step is worth.

## When a deploy fails

Every stage is idempotent, and a failed deploy is **re-run, not undone**. The
output names the stage that failed and the ones that had already finished, so
a second run picks up rather than starting over.

Run `bin/deploy.sh --dry-run` first on any host nobody has deployed to before.
It reports what each stage would do and changes nothing, which is the cheapest
way to find out that a machine is not deployable.
