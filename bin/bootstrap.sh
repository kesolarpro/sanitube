#!/usr/bin/env bash
#
# SaniTube bootstrap: from a bare host to "the installer can run".
#
# This is the single entrypoint a fresh machine sees first, so it is held to
# the strictest rules in the repository:
#
#   * Detection before action, always. It never installs a package on a
#     machine it has not read, and by default it installs nothing at all —
#     it prints the exact commands and stops. `--yes-install-packages` is
#     the operator saying "do it", and it is honoured only with root and a
#     recognised package manager.
#   * Nothing downloaded is ever piped into a shell. The one download it can
#     perform — Composer's installer — is verified against the publisher's
#     current SHA-384 before PHP touches it, exactly the procedure
#     getcomposer.org documents.
#   * It is not the installer. It ends by handing over to `sanitube:host`
#     and `sanitube:install`, which own profiles, stages and resumability.
#     A bootstrap that grew stages would be a second installer nobody tests.
#
# Usage, from the application directory:
#   bin/bootstrap.sh                        detect, report, print what to do
#   bin/bootstrap.sh --yes-install-packages same, but run the package steps
#
set -euo pipefail

YES_INSTALL=""

for argument in "$@"; do
    case "$argument" in
        --yes-install-packages) YES_INSTALL="yes" ;;
        *) echo "Unknown option: $argument" >&2; exit 64 ;;
    esac
done

cd "$(dirname "$0")/.."

say() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }
advise() { printf '    %s\n' "$1"; }

MISSING=""

# ---------------------------------------------------------------- detection

say "Reading this machine"

PM=""
for candidate in apt-get dnf yum zypper apk; do
    if command -v "$candidate" >/dev/null 2>&1; then PM="$candidate"; break; fi
done

if [ -n "$PM" ]; then
    advise "package manager: $PM"
else
    advise "package manager: none recognised — package steps become manual"
fi

IS_ROOT="no"
if [ "$(id -u)" = "0" ]; then IS_ROOT="yes"; fi
advise "root: $IS_ROOT"

# ---------------------------------------------------------------------- php

if command -v php >/dev/null 2>&1; then
    PHP_VERSION="$(php -r 'echo PHP_VERSION;')"
    advise "php: $PHP_VERSION"

    if ! php -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);'; then
        echo "PHP $PHP_VERSION is older than 8.2, which SaniTube requires." >&2
        MISSING="$MISSING php"
    fi
else
    advise "php: absent"
    MISSING="$MISSING php"
fi

php_install_hint() {
    # Package names differ per family; these are the distro's own names for
    # PHP and the extensions the platform requires.
    case "$PM" in
        apt-get) echo "apt-get update && apt-get install -y php-cli php-mysql php-sqlite3 php-xml php-mbstring php-curl php-zip php-gd php-intl" ;;
        dnf|yum) echo "$PM install -y php-cli php-mysqlnd php-pdo php-xml php-mbstring php-curl php-zip php-gd php-intl" ;;
        zypper)  echo "zypper install -y php8 php8-mysql php8-sqlite php8-xmlreader php8-mbstring php8-curl php8-zip php8-gd php8-intl" ;;
        apk)     echo "apk add php php-cli php-pdo_mysql php-pdo_sqlite php-xml php-mbstring php-curl php-zip php-gd php-intl php-tokenizer php-fileinfo" ;;
        *)       echo "install PHP 8.2+ with the extensions listed in .github/workflows/ci.yml" ;;
    esac
}

# ----------------------------------------------------------------- composer

if command -v composer >/dev/null 2>&1; then
    advise "composer: $(command -v composer)"
else
    advise "composer: absent"
    MISSING="$MISSING composer"
fi

install_composer() {
    # The publisher's documented procedure, verbatim in shape: download the
    # installer, compare its SHA-384 against the value the publisher serves,
    # refuse on any difference. Never piped into anything.
    say "Installing Composer (verified)"
    EXPECTED="$(php -r "copy('https://composer.github.io/installer.sig', 'php://stdout');")"
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    ACTUAL="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"

    if [ "$EXPECTED" != "$ACTUAL" ]; then
        rm -f composer-setup.php
        echo "Composer installer checksum mismatch — refusing to run it. Try again; if it persists, something between you and getcomposer.org is rewriting downloads." >&2
        exit 70
    fi

    php composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm -f composer-setup.php
    advise "composer: $(command -v composer)"
}

# ---------------------------------------------------------------------- git

if command -v git >/dev/null 2>&1; then
    advise "git: $(command -v git)"
else
    advise "git: absent (needed for git-based deploys; a release archive works without it)"
fi

# ------------------------------------------------------------------- action

if [ -n "$MISSING" ]; then
    say "Missing:$MISSING"

    if [ -n "$YES_INSTALL" ] && [ "$IS_ROOT" = "yes" ] && [ -n "$PM" ]; then
        case "$MISSING" in *php*)
            say "Installing PHP via $PM"
            sh -c "$(php_install_hint)"
        ;; esac

        case "$MISSING" in *composer*)
            install_composer
        ;; esac
    else
        say "Nothing was installed (no --yes-install-packages, no root, or no package manager). Run these yourself:"

        case "$MISSING" in *php*)
            advise "$(php_install_hint)"
        ;; esac

        case "$MISSING" in *composer*)
            advise "composer: follow https://getcomposer.org/download/ — it verifies the installer's SHA-384, and so does this script with --yes-install-packages"
        ;; esac

        exit 69
    fi
fi

# ----------------------------------------------------------------- handover

say "Dependencies"
# A fresh VPS bootstrap runs as root before any service account exists, and
# Composer as root refuses plugins unless told the operator meant it. This
# is that telling — scoped to this one command, not exported to the shell.
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader

say "This machine, read by SaniTube"
php artisan sanitube:host

say "Next"
advise "php artisan sanitube:install --profile=<from the suggestion above>"
advise "  unattended: add --config=/root/sanitube-install.conf (chmod 600) — see docs/deployment/installation.md"
advise "then: php artisan sanitube:provision --into=... (VPS) and php artisan sanitube:frontend:install <artifact>"
