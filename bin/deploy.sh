#!/usr/bin/env bash
#
# SaniTube deployment.
#
# The shell half of a deploy. The PHP half is `php artisan sanitube:deploy`,
# and the split is not tidiness: PHP cannot safely rewrite its own autoloader
# mid-request, so a deploy command that ran `composer install` on itself would
# finish half on the old code and half on the new.
#
# What this script will not do, deliberately:
#
#   * It never runs `git pull`. Which revision is deployed is the deployer's
#     decision — a CI runner, a hook, or a person — and a script that decides
#     for them is a script that deploys the wrong branch one day.
#   * It never creates a database, a `.env` or an owner. That is
#     `sanitube:install`. Being asked to deploy onto a machine with none of
#     them means somebody ran the wrong command, and inventing them turns that
#     mistake into a second, empty installation nobody notices.
#   * It never drops, rebuilds or seeds the database. There is a live catalogue
#     on the other side, and the test that enforces this reads the script as
#     text — so naming the forbidden commands here, even to disown them, would
#     trip it. That is the guard working, not a false positive.
#
# Usage:
#   bin/deploy.sh              deploy
#   bin/deploy.sh --dry-run    report what would happen and change nothing
#
# Every step is idempotent. A deploy that fails halfway is re-run, not undone.

set -euo pipefail

DRY_RUN=""
for argument in "$@"; do
    case "$argument" in
        --dry-run) DRY_RUN="--dry-run" ;;
        *) echo "Unknown option: $argument" >&2; exit 64 ;;
    esac
done

cd "$(dirname "$0")/.."

say() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

# Fail early and by name. "composer: command not found" three minutes into a
# deploy, after the maintenance page is already up, is the worst moment to
# discover a missing tool.
for tool in php composer; do
    command -v "$tool" >/dev/null 2>&1 || { echo "Required tool not found: $tool" >&2; exit 69; }
done

if [ -n "$DRY_RUN" ]; then
    say "Dry run — nothing will be changed"
    php artisan sanitube:deploy --dry-run
    exit $?
fi

# Maintenance mode first, and released by a trap so that a failure anywhere
# below does not leave the installation dark. `|| true` on the way up because
# a deploy that has already failed must not be reported as failing to recover
# instead.
say "Maintenance mode"
php artisan down --render="errors::503" --retry=15 || true
trap 'php artisan up || true' EXIT

say "PHP dependencies"
# --no-dev because a production installation should not carry a test
# framework, and --optimize-autoloader because the classmap is read on every
# single request.
composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader

# The frontend is optional here on purpose. Shared hosting rarely has Node,
# and the usual answer there is to build elsewhere and upload public/build —
# so a missing npm is a note, not a failure.
if command -v npm >/dev/null 2>&1; then
    say "Frontend"
    npm ci --no-audit --no-fund
    npm run build
else
    say "Frontend — skipped"
    echo "npm was not found. Build the assets elsewhere and upload public/build."
fi

say "Schema, caches, workers"
php artisan sanitube:deploy

say "Done"
