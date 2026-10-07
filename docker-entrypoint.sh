#!/bin/sh
set -e

# ---------------------------------------------------------------------------
# Bootstrap .env if the platform hasn't injected one
# ---------------------------------------------------------------------------
if [ ! -f /var/www/html/.env ]; then
    cp /var/www/html/.env.example /var/www/html/.env
fi

# ---------------------------------------------------------------------------
# Clear any stale bootstrap caches baked into the image, then rebuild them.
# We run each command in a subshell so a non-fatal failure doesn't kill the
# container — artisan exits non-zero when (e.g.) the DB isn't reachable yet
# during view:cache, but Apache should still start.
# ---------------------------------------------------------------------------
php artisan config:clear  --quiet || true
php artisan route:clear   --quiet || true
php artisan view:clear    --quiet || true

php artisan config:cache  --quiet || true
php artisan route:cache   --quiet || true
# view:cache is optional — skip it; views compile on first request instead
# php artisan view:cache  --quiet || true

# Run migrations if the DB is reachable (non-fatal)
php artisan migrate --force --quiet || true

exec "$@"
