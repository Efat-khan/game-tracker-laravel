#!/bin/sh
set -e

# Wait for MySQL. The app is useless without it and a crash loop is noisier
# than a short wait.
if [ -n "${DB_HOST:-}" ]; then
    echo "Waiting for MySQL at ${DB_HOST}:${DB_PORT:-3306}…"
    for i in $(seq 1 60); do
        if php -r "exit(@fsockopen(getenv('DB_HOST'), (int)(getenv('DB_PORT') ?: 3306)) ? 0 : 1);"; then
            break
        fi
        sleep 2
    done
fi

php artisan migrate --force

# Idempotent: it skips if the demo cafe already has stations.
if [ "${SEED_ON_BOOT:-false}" = "true" ]; then
    php artisan db:seed --force
fi

if [ "${APP_ENV:-production}" = "production" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

exec "$@"
