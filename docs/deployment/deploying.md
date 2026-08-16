# Deploying SaniTube

Deploying updates a **running** installation. Installing sets one up. They look
alike and they are not: an install may create a database, an application key
and an owner, and a deploy must create none of those. If they are missing, you
are running the wrong command — see [installation.md](installation.md).

```
bin/deploy.sh --dry-run     # report what would happen, change nothing
bin/deploy.sh               # deploy
```

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

`npm` is optional. If it is not on the host the script says so and carries on —
build the assets on a machine that has Node and upload `public/build`. See
[cpanel.md](cpanel.md) when it lands.

## When a deploy fails

Every stage is idempotent, and a failed deploy is **re-run, not undone**. The
output names the stage that failed and the ones that had already finished, so
a second run picks up rather than starting over.

Run `bin/deploy.sh --dry-run` first on any host nobody has deployed to before.
It reports what each stage would do and changes nothing, which is the cheapest
way to find out that a machine is not deployable.
