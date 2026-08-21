<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Where backups are written
    |--------------------------------------------------------------------------
    |
    | Outside `public/`, and the code refuses a destination inside it rather
    | than trusting this setting. A backup contains every row of the catalogue
    | and every credential-shaped value in the database; one served over HTTP
    | is worse than no backup at all.
    |
    */

    'destination' => env('SANITUBE_BACKUP_PATH', storage_path('backups')),

    /*
    |--------------------------------------------------------------------------
    | How many complete backups to keep
    |--------------------------------------------------------------------------
    |
    | Pruning only ever removes *complete* backups, oldest first, and never the
    | one just written. An incomplete directory is left alone: it is either a
    | run in progress or the evidence of a failure, and both are things an
    | operator should find rather than have tidied away.
    |
    */

    'keep' => (int) env('SANITUBE_BACKUP_KEEP', 7),

    /*
    |--------------------------------------------------------------------------
    | Tables whose contents are not worth restoring
    |--------------------------------------------------------------------------
    |
    | Ephemeral by nature: a cache entry, a session or a rate-limiter counter
    | restored from last week is worse than an empty one. The *structure* is
    | still restored — only the rows are skipped — and the manifest records
    | every exclusion, because a backup that quietly leaves something out is a
    | backup nobody can reason about.
    |
    */

    'skip_table_contents' => ['cache', 'cache_locks', 'sessions', 'password_reset_tokens'],

    /*
    |--------------------------------------------------------------------------
    | Files included alongside the database
    |--------------------------------------------------------------------------
    |
    | Relative to the application root. Audio masters are deliberately **not**
    | here: several hundred of them are tens of gigabytes, copying them on
    | every backup is not a backup strategy, and pretending otherwise is how an
    | operator discovers their nightly job has been silently failing on disk
    | space. Masters belong in object storage or in a separate file-level
    | backup, and the operator documentation says so.
    |
    | The manifest records what was included *and* what was not.
    |
    | Checked, not trusted. A path here is refused before the backup directory
    | is created if it resolves outside the application, if it is the
    | application root, or if it touches the backup destination — `storage` is
    | the obvious thing to write and would copy every previous backup into the
    | new one, doubling on each run until the disk is full. Environment files
    | are never copied whatever this says, and a backup naming one is refused
    | on restore: `.env` holds every credential this installation has.
    |
    */

    'include_paths' => ['storage/app/public'],

];
