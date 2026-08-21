# Backing up and restoring SaniTube

```
php artisan sanitube:backup            # take one
php artisan sanitube:restore --verify  # check the newest is restorable
php artisan sanitube:restore           # put it back (asks first)
```

## What a backup is

A **directory**, not an archive. `ZipArchive` and `PharData` are optional PHP
extensions and shared hosting is the baseline target, so a backup that needed
one would be a backup that sometimes cannot be taken. Tar it yourself if you
have tar; copy it if you do not.

```
storage/backups/2026-08-16_031500/
├── manifest.json        ← what this backup is. Written last.
├── database.jsonl       ← every row, as data
└── files/…              ← the files listed in backup.include_paths
```

**The manifest is written last.** Until it exists the directory is not a
backup: it will not be offered for restore and it will not be pruned. That is
the whole crash-safety story — there is no moment at which a partial backup
looks complete.

## What is in it, and what is not

| | |
|---|---|
| Every table's rows | **yes** |
| `cache`, `sessions`, and other ephemeral tables | structure yes, rows **no** |
| Covers and public files (`storage/app/public`) | **yes** |
| **Audio masters** | **no** |

Audio masters are deliberately excluded. Several hundred of them are tens of
gigabytes; copying them on every run is not a backup strategy, and pretending
otherwise is how an operator discovers their nightly job has been failing on
disk space for a month. Put masters in object storage, or back them up at the
file level on their own schedule.

**Every exclusion is recorded in the manifest**, with its reason. A backup that
quietly omits something is a backup nobody can reason about.

## The database is dumped as rows, not as SQL

No `mysqldump`. Shared hosting frequently has no shell, no `exec()` and no
client binaries.

It is also a portability decision. A SQL dump is written in one engine's
dialect, and SaniTube runs on SQLite, MySQL 8, MariaDB 10.6 and MariaDB 11.4. A
label moving from a shared host to a VPS is moving engines, and rows-as-data
travel where `INSERT` statements do not. The *schema* comes from migrations,
which are already engine-neutral.

## Restoring

A restore is the most destructive operation in the platform. It refuses, in
this order, and every refusal happens **before anything is written**:

1. **Is it a backup?** A manifest, readable, in a format this version knows.
2. **Is it complete?** Every entry it names is present.
3. **Is it intact?** Every checksum matches. Restoring damage over working data
   is worse than not restoring.
4. **Does it belong here?** The migration set must match. Restoring older rows
   into a newer schema silently is how a catalogue is corrupted; pass
   `--allow-schema-drift` if you know and accept it.
5. **Was it asked for?** Explicitly.

`--verify` runs steps one to four and changes nothing. **Put it on a schedule.**
A backup nobody has ever tried to restore is a belief, not a backup.

### A restore cannot happen by accident

`--force` exists for automation and is spelled out. It is deliberately *not*
implied by `--no-interaction`: a deploy script that acquired the power to
restore by being non-interactive would be one stray line away from replacing a
live catalogue.

### Files and the database are not restored atomically

They cannot be. The database goes back inside a transaction; files are copied
afterwards.

If the file copy fails, the database is already consistent with the backup and
you are told how many files landed. That is recoverable. The other order —
files first — would leave a backup's covers beside a database that never
received it, which is not.

**After a restore, rebuild the caches and restart the workers:**

```
bin/deploy.sh          # or: php artisan sanitube:deploy
```

## Retention

`backup.keep` (default 7) complete backups are kept, oldest pruned first.

- Pruning **never** removes an incomplete backup. That is either a run in
  progress or the evidence of a failure, and both are things you should find.
- Pruning **never** removes everything, whatever `keep` says. A mistyped
  environment variable must not mean "delete all backups the moment the next
  one succeeds".
- Pruning does not follow symlinks out of the backup directory.

## Where backups go

`backup.destination`, default `storage/backups`.

**It is refused if it is inside `public/`.** A backup holds every row of the
catalogue; one served over HTTP is worse than no backup at all. The code checks
rather than trusting the setting.

Backups are **not** encrypted. Treat the directory as you would the database
itself, and do not copy it anywhere you would not copy `.env`.

## What goes in, and what cannot

`backup.include_paths`, default `storage/app/public`. Relative to the
application root, and **checked before the backup directory is created** — a
mistake here stops the run rather than leaving you a half-written directory to
clean up on top of the setting to fix.

Four entries are refused:

| Entry | Refusal | Why |
| --- | --- | --- |
| `..`, or anything resolving outside the installation | `INCLUDE_PATH_ESCAPES` | On shared hosting the directory above the installation is somebody else's account. Resolved first, so a symlink pointing out of the tree is caught too. |
| `.` | `INCLUDE_PATH_IS_APPLICATION` | That is a copy of the installation — `vendor`, `node_modules` and `.git` — not a backup of its data. |
| `storage`, or anything containing `backup.destination` | `INCLUDE_PATH_IS_DESTINATION` | The default destination is inside `storage/`, so this copies every previous backup into the new one. Each run doubles, quietly, until the disk is full and the nightly job has been failing for a week. |
| A path that is not there | *not* refused | Recorded in the manifest's `excluded` instead, as `path:<entry>`. A path in the configuration and nothing on disk is somebody who believes something is backed up; the manifest is where they find out. |

**`.env` is never in a backup.** Not `.env`, not `.env.production`, not at any
depth, whatever `include_paths` says — it holds every credential this
installation has, and a backup carrying one hands them to whoever holds the
backup. Every manifest states this under `files:environment`, so a manifest
read six months from now says so without you having to work it out from the
file list.

The same rule applies on the way back in: `sanitube:restore` refuses a backup
whose manifest names an environment file, with `ENVIRONMENT_FILE_IN_BACKUP`,
*before* it writes anything. A backup taken by an older SaniTube, or one
somebody edited, would otherwise write another installation's credentials over
your live ones — and it would arrive having passed every other check, because
the checksum of a tampered backup matches the manifest that was tampered with
beside it.

Everything else in an included directory is taken, hidden files included.

## On cPanel

Add a cron entry:

```
0 3 * * * /usr/local/bin/php /home/USER/sanitube/artisan sanitube:backup >> /dev/null 2>&1
```

Then download `storage/backups/` periodically, or point `backup.destination` at
a directory your host's own backup covers. **Off the machine is the operative
part** — a backup on the same disk survives a mistake and not a failure.

## On a VPS

The same command, plus a weekly verify:

```
0 3 * * * cd /srv/sanitube && php artisan sanitube:backup
0 4 * * 0 cd /srv/sanitube && php artisan sanitube:restore --verify
```

Copy `storage/backups/` off the machine — `rsync`, `restic`, an object store,
anything that is not the disk the database is on.

## Restoring onto a different machine

Supported, and the reason the dump is engine-neutral:

1. Install SaniTube and run `php artisan sanitube:install`.
2. Run migrations to the **same set** the backup records — this is what step
   four of the restore checks.
3. Copy the backup directory across.
4. `php artisan sanitube:restore /path/to/backup --verify`, then without
   `--verify`.
5. Restore audio masters separately, from wherever you keep them.
