#!/usr/bin/env bash
#
# SaniTube update: bring a running installation to a named revision.
#
# bin/deploy.sh deploys the code that is already on disk and never touches
# git — which revision to deploy is the deployer's decision. This script is
# that decision being executed: the revision is a REQUIRED argument, verified
# to exist before anything stops, and there is deliberately no default. An
# update script that assumed "whatever main is now" would deploy somebody
# else's afternoon.
#
# Order of operations, and why:
#
#   1. refuse a dirty tree      — local edits under a deploy are either
#                                 somebody's uncommitted fix (losing it is a
#                                 disaster) or an intrusion (overwriting it
#                                 destroys the evidence);
#   2. fetch + verify the ref   — everything that can fail before the site
#                                 blinks, fails before the site blinks;
#   3. database backup          — the one artefact that turns a failed
#                                 migration from an incident into a bad hour;
#   4. maintenance on (trap!)   — released even when a later step dies;
#   5. checkout, composer       — the tree now IS the target revision;
#   6. frontend, if provided    — the matching artifact for that revision;
#   7. php artisan sanitube:deploy — migrations under the deploy lock,
#                                 caches, storage link, worker signal;
#   8. doctor, then smoke       — a blocking FAIL exits non-zero, loudly,
#                                 after the site is back up: up on the new
#                                 code beats dark while somebody reads logs.
#                                 The smoke half is real HTTP against the
#                                 deployed URL, forbidden paths included.
#
# What it never does: choose a revision, run with a dirty tree, skip the
# backup, or leave maintenance mode on after a failure.
#
# Usage:
#   bin/update.sh <ref> [--frontend=/path/to/sanitube-frontend-build.zip] [--dry-run]
#
#   <ref>  a tag, a branch name, or a commit SHA — fetched from origin and
#          resolved before anything else happens.

set -euo pipefail

REF=""
FRONTEND=""
DRY_RUN=""

for argument in "$@"; do
    case "$argument" in
        --frontend=*) FRONTEND="${argument#--frontend=}" ;;
        --dry-run) DRY_RUN="yes" ;;
        --*) echo "Unknown option: $argument" >&2; exit 64 ;;
        *)
            if [ -n "$REF" ]; then echo "Exactly one revision, please." >&2; exit 64; fi
            REF="$argument"
            ;;
    esac
done

if [ -z "$REF" ]; then
    echo "Which revision? bin/update.sh <tag|branch|sha> — there is no default on purpose." >&2
    exit 64
fi

cd "$(dirname "$0")/.."

say() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

for tool in php composer git; do
    command -v "$tool" >/dev/null 2>&1 || { echo "Required tool not found: $tool" >&2; exit 69; }
done

# 1. A dirty tree stops everything, by name and count.
DIRTY="$(git status --porcelain)"
if [ -n "$DIRTY" ]; then
    echo "The working tree has local modifications:" >&2
    echo "$DIRTY" | head -20 >&2
    echo "An update will not run over them: commit, stash, or investigate first." >&2
    exit 65
fi

# 2. Resolve the target before anything stops.
say "Fetching $REF"
git fetch origin "$REF"
TARGET="$(git rev-parse --verify FETCH_HEAD)"
PREVIOUS="$(git rev-parse --verify HEAD)"

if [ "$TARGET" = "$PREVIOUS" ]; then
    say "Already at $TARGET — nothing to update. Re-running the deploy half only."
fi

if [ -n "$DRY_RUN" ]; then
    say "Dry run"
    echo "Would update $PREVIOUS -> $TARGET"
    php artisan sanitube:deploy --dry-run
    exit $?
fi

# 3. The backup is not optional and not after: a failed migration with no
# backup is an incident, with one it is a bad hour.
say "Backing up the database"
php artisan sanitube:backup

# 4. Maintenance, released by trap so no failure below leaves the site dark.
say "Entering maintenance mode"
php artisan down --retry=15 || true
trap 'php artisan up || true' EXIT

# 5. The tree becomes the target revision.
say "Checking out $TARGET"
git -c advice.detachedHead=false checkout --detach "$TARGET"

say "Installing dependencies"
composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader

# 6. The frontend that matches, when one was brought.
if [ -n "$FRONTEND" ]; then
    say "Installing the frontend build"
    php artisan sanitube:frontend:install "$FRONTEND" --sha="$TARGET"
else
    say "No frontend artifact provided — public/build stays as it is. If templates changed, fetch the artifact for $TARGET."
fi

# 7. Migrations under the deploy lock, caches, storage link, worker signal.
say "Deploying"
php artisan sanitube:deploy

# 8. Up first, explicitly, then the checks that need the site to be up. The
# trap stays armed until here so any failure above still lifts maintenance;
# from this point the site is up and the trap has nothing left to do.
say "Leaving maintenance mode"
php artisan up
trap - EXIT

say "Doctor"
if ! php artisan sanitube:doctor; then
    say "Deployed, but the doctor names blockers above. The site is up on $TARGET; fix what it says."
    exit 70
fi

# 9. Real HTTP against the deployed installation — the only proof that
# counts. Skipped only when APP_URL is not http(s), which the smoke command
# itself refuses with instructions.
say "Smoke"
if ! php artisan sanitube:smoke; then
    say "Deployed, but the smoke tests failed above. The site is up on $TARGET; fix what they say."
    exit 71
fi

say "Updated $PREVIOUS -> $TARGET"
echo "To go back: bin/update.sh $PREVIOUS (schema changes do not reverse; restore the backup only as a deliberate act)."
