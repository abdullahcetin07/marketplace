#!/bin/sh
###############################################################################
# Production container entrypoint.
#
# Runs before the main process on every container start. Deliberately does NOT
# run migrations: several containers start at once, and concurrent `migrate`
# is a race that eventually corrupts the schema. Migrations belong to a single
# deploy job. @see docs/deployment.md
###############################################################################
set -e

echo "[entrypoint] MarketplaceOS starting (APP_ENV=${APP_ENV:-production})"

# Fail fast and loudly rather than booting into an unencryptable state.
if [ -z "${APP_KEY}" ]; then
    echo "[entrypoint] FATAL: APP_KEY is not set." >&2
    exit 1
fi

# Config/route/event caches are baked into the image at build time. Rebuild
# them only if they are missing (e.g. a volume mount shadowed bootstrap/cache).
if [ ! -f bootstrap/cache/config.php ]; then
    echo "[entrypoint] Config cache missing — rebuilding."
    php artisan config:cache
    php artisan route:cache
    php artisan event:cache
fi

# Wait for the database. Without this, a container that starts fractionally
# before Postgres crash-loops and pollutes the error tracker.
if [ "${WAIT_FOR_DB:-true}" = "true" ]; then
    echo "[entrypoint] Waiting for database..."
    attempts=0
    until php artisan db:show --quiet >/dev/null 2>&1; do
        attempts=$((attempts + 1))
        if [ "$attempts" -ge 30 ]; then
            echo "[entrypoint] FATAL: database unreachable after 30 attempts." >&2
            exit 1
        fi
        sleep 2
    done
    echo "[entrypoint] Database reachable."
fi

# The storage symlink lives outside the image layer when storage is a volume.
php artisan storage:link --quiet 2>/dev/null || true

echo "[entrypoint] Ready."

exec "$@"
