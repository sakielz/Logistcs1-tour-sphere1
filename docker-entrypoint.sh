#!/bin/sh
set -e

# Render and cloud platforms dynamically provide $PORT (or user may configure custom port)
# Default to 80 if not set.
APP_PORT="${PORT:-80}"

echo "Starting GlobalSCM Logistics Apache container on port ${APP_PORT}..."

# Update Apache port and vhost to match the runtime port while preserving the default
# Dockerfile vhost placeholder.
sed -i "s/__PORT__/${APP_PORT}/g" /etc/apache2/sites-available/000-default.conf
sed -i "s/Listen [0-9]*/Listen ${APP_PORT}/g" /etc/apache2/ports.conf
printf 'Listen %s\n' "${APP_PORT}" > /etc/apache2/ports.conf

# Ensure writable directories exist
mkdir -p /var/www/html/uploads /var/www/html/database /var/www/html/storage/logs \
    /var/www/html/storage/framework/cache /var/www/html/storage/framework/sessions /var/www/html/storage/framework/views
chown -R www-data:www-data /var/www/html/uploads /var/www/html/database /var/www/html/storage 2>/dev/null || true
chmod -R 775 /var/www/html/uploads /var/www/html/database /var/www/html/storage 2>/dev/null || true

# Bootstrap .env when the platform hasn't injected one.
if [ ! -f /var/www/html/.env ]; then
    cp /var/www/html/.env.example /var/www/html/.env
fi

# Laravel bootstrap — non-fatal so Apache always starts.
php artisan config:clear --quiet || true
php artisan route:clear --quiet || true
php artisan view:clear --quiet || true

php artisan config:cache --quiet || true
php artisan route:cache --quiet || true

# Run migrations (non-fatal — DB may not be reachable yet on very first deploy).
php artisan migrate --force --quiet || true

# Execute Apache in foreground.
exec "$@"

