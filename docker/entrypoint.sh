#!/bin/sh
# Entry point of the NightGuardian image (web app, queue worker and scheduler share it).
#
#  - APP_KEY: if it is not given, one is generated once and kept in the storage volume, so that the web app, the
#    worker and the scheduler (separate containers) all use the same key.
#  - waits for the database,
#  - RUN_MIGRATIONS=true (set on the web app only): migrates and caches configuration on every start,
#  - runs the command as www-data (Apache starts as root and drops privileges itself).
set -e

cd /var/www/html

if [ -z "${APP_KEY:-}" ]; then
    key_file=storage/app/.app_key
    mkdir -p storage/app

    (
        flock 9
        if [ ! -s "$key_file" ]; then
            php artisan key:generate --show > "$key_file"
            chown www-data:www-data "$key_file"
            chmod 600 "$key_file"
        fi
    ) 9> storage/app/.app_key.lock

    APP_KEY=$(cat "$key_file")
    export APP_KEY
fi

if [ "${NG_WAIT_FOR_DB:-true}" = "true" ]; then
    attempts=0
    until php -r '
        try {
            new PDO(
                "mysql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT") ?: 3306).";dbname=".getenv("DB_DATABASE"),
                getenv("DB_USERNAME"),
                getenv("DB_PASSWORD")
            );
        } catch (Throwable $e) {
            exit(1);
        }
    ' 2> /dev/null; do
        attempts=$((attempts + 1))
        if [ "$attempts" -ge 60 ]; then
            echo "The database is not reachable at ${DB_HOST:-?}:${DB_PORT:-3306}." >&2
            exit 1
        fi
        sleep 2
    done
fi

as_app() {
    runuser -u www-data -- "$@"
}

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    as_app php artisan migrate --force
    as_app php artisan optimize
fi

if [ "$1" = "apache2-foreground" ]; then
    exec "$@"
fi

exec runuser -u www-data -- "$@"
