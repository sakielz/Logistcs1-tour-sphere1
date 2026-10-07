#!/bin/sh
set -e

# ---------------------------------------------------------------------------
# 1. Resolve the port HostForge injects (defaults to 80)
# ---------------------------------------------------------------------------
APP_PORT="${PORT:-80}"

# Patch the vhost and ports.conf with the real port at runtime.
# (The Dockerfile baked in __PORT__ as a safe placeholder.)
sed -i "s/__PORT__/${APP_PORT}/g" /etc/apache2/sites-available/000-default.conf
printf 'Listen %s\n' "${APP_PORT}" > /etc/apache2/ports.conf

# ---------------------------------------------------------------------------
# 2. Bootstrap .env if the platform hasn't injected one
# ---------------------------------------------------------------------------
if [ ! -f /var/www/html/.env ]; then
    cp /var/www/html/.env.example /var/www/html/.env
fi

# ---------------------------------------------------------------------------
# 3. Laravel bootstrap — non-fatal so Apache always starts
# ---------------------------------------------------------------------------
php artisan config:clear --quiet || true
php artisan route:clear  --quiet || true
php artisan view:clear   --quiet || true

php artisan config:cache --quiet || true
php artisan route:cache  --quiet || true

# Run migrations (non-fatal — DB may not be reachable yet on very first deploy)
php artisan migrate --force --quiet || true

# ---------------------------------------------------------------------------
# 4. Start Apache
# ---------------------------------------------------------------------------
exec "$@"
