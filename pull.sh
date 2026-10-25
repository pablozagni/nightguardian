#!/usr/bin/env bash
#
# Production deploy. Run as root (e.g. from a two-line wrapper: `bash /path/to/this/pull.sh`).
# Puts the app in maintenance mode, updates the code and dependencies, builds assets,
# migrates and brings the app back up -- even when a step fails.
#
# Optional, git-ignored `deploy.env` next to this file, e.g.:
#   RESTART_SERVICES="nightguardian-worker"
#   PHP_BIN=/usr/bin/php8.3

set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"

if [[ -f "$ROOT/deploy.env" ]]; then
    set -a
    # shellcheck disable=SC1091
    source "$ROOT/deploy.env"
    set +a
fi

PHP_BIN="${PHP_BIN:-$(command -v php8.3 || command -v php)}"
COMPOSER_BIN="$(command -v composer2 || command -v composer)"
export COMPOSER_ALLOW_SUPERUSER=1

artisan() { "$PHP_BIN" artisan "$@"; }

APP_DOWN=0
bring_up() {
    local code=$?
    if [[ $APP_DOWN -eq 1 ]]; then
        artisan up || echo "WARNING: could not bring the app up, run: php artisan up" >&2
        APP_DOWN=0
    fi
    if [[ $code -ne 0 ]]; then
        echo "Deploy failed (exit $code). The app was brought back up with whatever code is on disk." >&2
    fi
    exit "$code"
}
trap bring_up EXIT

# .env is complete when every key needed to boot has a value.
env_complete() {
    local file="$ROOT/.env" key value
    [[ -f "$file" ]] || return 1
    for key in APP_KEY APP_URL DB_DATABASE DB_USERNAME; do
        value="$(grep -E "^${key}=" "$file" | tail -n1 | cut -d= -f2- | tr -d "\"' " || true)"
        [[ -n "$value" ]] || { echo "Missing ${key} in .env" >&2; return 1; }
    done
}

echo "==> Maintenance mode"
if [[ -f "$ROOT/vendor/autoload.php" && -f "$ROOT/.env" ]]; then
    artisan down --retry=60 && APP_DOWN=1
else
    echo "    (first deploy: no vendor or .env yet, continuing without maintenance mode)"
fi

echo "==> git pull"
git -c safe.directory="$ROOT" pull --ff-only

echo "==> composer install"
"$PHP_BIN" "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction

if [[ -f package.json ]] && grep -q '"build"' package.json; then
    echo "==> npm ci && npm run build"
    npm ci
    npm run build
fi

if [[ ! -f "$ROOT/.env" ]]; then
    echo "Missing .env: copy .env.example, fill it in and run pull.sh again." >&2
    exit 1
fi

echo "==> migrate"
artisan migrate --force

echo "==> filament:assets"
artisan filament:assets

echo "==> storage:link"
artisan storage:link || true

if env_complete; then
    echo "==> optimize"
    artisan optimize
else
    echo "==> .env incomplete: skipping optimize" >&2
    artisan optimize:clear
fi

if [[ -n "${RESTART_SERVICES:-}" ]]; then
    for service in ${RESTART_SERVICES//,/ }; do
        echo "==> systemctl restart ${service}"
        systemctl restart "$service"
    done
fi

chown -R www-data:www-data "$ROOT"
echo "Deploy nightguardian OK"
