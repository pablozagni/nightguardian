#!/bin/bash
#
# Deploy de nightguardian — ejecutar como root: bash /var/www/<dominio>/pull.sh
# Plantilla común de pull.sh (ver "Convención de pull.sh" en el README de zpanel).
#
# Opcional, `deploy.env` (git-ignorado) junto a este archivo, p. ej.:
#   RESTART_SERVICES="nightguardian-worker"
#   PHP_BIN=/usr/bin/php8.3
#
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$APP_DIR"

if [[ -f "$APP_DIR/deploy.env" ]]; then
    set -a
    # shellcheck disable=SC1091
    source "$APP_DIR/deploy.env"
    set +a
fi

PHP_BIN="${PHP_BIN:-$(command -v php8.3 || command -v php)}"
COMPOSER_BIN="$(command -v composer2 || command -v composer)"
export COMPOSER_ALLOW_SUPERUSER=1

artisan() {
    runuser -u www-data -- "$PHP_BIN" artisan "$@"
}

# Git y Composer crean archivos como root: se devuelve todo el árbol (menos .git) a www-data.
fix_permissions() {
    find . -path ./.git -prune -o -exec chown -h www-data:www-data {} +
}

# El .env está completo cuando todas las claves necesarias para arrancar tienen valor.
env_complete() {
    local file="$APP_DIR/.env" key value
    [[ -f "$file" ]] || return 1
    for key in APP_KEY APP_URL DB_DATABASE DB_USERNAME; do
        value="$(grep -E "^${key}=" "$file" | tail -n1 | cut -d= -f2- | tr -d "\"' " || true)"
        [[ -n "$value" ]] || { echo "Falta ${key} en .env" >&2; return 1; }
    done
}

APP_DOWN=0
bring_up() {
    local code=$?
    if [[ $APP_DOWN -eq 1 ]]; then
        artisan up || echo "AVISO: no se pudo levantar el sitio, ejecutá: php artisan up" >&2
        APP_DOWN=0
    fi
    if [[ $code -ne 0 ]]; then
        echo "!! Fallo en el deploy (exit $code). El sitio quedó en línea con el código que hay en disco." >&2
    fi
    exit "$code"
}
trap bring_up EXIT

# Primer deploy: todavía no hay vendor ni .env, así que no hay Artisan para activar mantenimiento.
echo "==> Mantenimiento"
if [[ -f "$APP_DIR/vendor/autoload.php" && -f "$APP_DIR/.env" ]]; then
    artisan down --retry=60 && APP_DOWN=1
else
    echo "    (primer deploy: sin vendor ni .env, se sigue sin mantenimiento)"
fi

# ── Git ────────────────────────────────────────────────────────────────────────
echo "==> Git"
BRANCH=$(git rev-parse --abbrev-ref HEAD)
git -c safe.directory="$APP_DIR" fetch origin
git -c safe.directory="$APP_DIR" reset --hard origin/"$BRANCH"

# ── Dependencias ───────────────────────────────────────────────────────────────
echo "==> Composer"
"$PHP_BIN" "$COMPOSER_BIN" install --no-dev --no-interaction --optimize-autoloader

# Los assets de Vite son opcionales (el panel es Filament y no tiene vistas con @vite):
# si el build falla es un aviso, no motivo para abortar el deploy.
build_assets() {
    if [[ -f package-lock.json ]]; then
        npm ci && npm run build
    else
        npm install --no-audit --no-fund && npm run build
    fi
}

if [[ -f package.json ]] && grep -Eq '"build"[[:space:]]*:' package.json; then
    echo "==> Frontend (Vite)"
    if ! build_assets; then
        echo "AVISO: falló el build de npm, se sigue sin public/build" >&2
    fi
else
    echo "==> Frontend (sin script build, se omite npm)"
fi

if [[ ! -f "$APP_DIR/.env" ]]; then
    echo "Falta .env: copiá .env.example, completalo y ejecutá pull.sh de nuevo." >&2
    exit 1
fi

# Artisan corre como www-data: se normaliza el dueño antes de usarlo.
echo "==> Permisos"
fix_permissions

# ── Base de datos ──────────────────────────────────────────────────────────────
echo "==> Migraciones"
artisan migrate --force

# ── Cachés ─────────────────────────────────────────────────────────────────────
echo "==> Cachés"
artisan filament:assets
artisan storage:link || true
if env_complete; then
    artisan optimize
else
    echo "AVISO: .env incompleto, se omite optimize" >&2
    artisan optimize:clear
fi

# ── Servicios ──────────────────────────────────────────────────────────────────
if [[ -n "${RESTART_SERVICES:-}" ]]; then
    for service in ${RESTART_SERVICES//,/ }; do
        echo "==> systemctl restart ${service}"
        systemctl restart "$service"
    done
fi

# Artisan puede haber creado archivos y subdirectorios como root.
echo "==> Permisos finales"
fix_permissions

# ── En línea ───────────────────────────────────────────────────────────────────
echo "==> Sitio en línea"
if [[ $APP_DOWN -eq 1 ]]; then
    artisan up
    APP_DOWN=0
fi
trap - EXIT   # deploy exitoso: desactivar el trap de emergencia

# ── OPcache ───────────────────────────────────────────────────────────────────
# No se recarga PHP-FPM: el OPcache es compartido por todas las apps del servidor y un
# reload lo vacía para todas. Con validate_timestamps=1 y revalidate_freq=15 PHP detecta
# los cambios solo; la invalidación por app hace que el cambio se vea de inmediato.
echo "==> OPcache (solo esta app)"
if [ -x /root/opcache_invalidate_app.sh ]; then /root/opcache_invalidate_app.sh "$APP_DIR" || true; fi

echo ""
echo "✓  Deploy completado — rama: $BRANCH — $(date '+%Y-%m-%d %H:%M:%S')"
