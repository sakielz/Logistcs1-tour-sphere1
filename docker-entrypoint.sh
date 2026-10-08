#!/bin/bash
set -e

# Render and cloud platforms dynamically provide $PORT (or user may configure custom port)
# Default to 80 if not set
PORT="${PORT:-80}"

echo "Starting GlobalSCM Logistics Apache container on port $PORT..."

# Update Apache listening port to match $PORT
sed -i "s/Listen [0-9]*/Listen $PORT/g" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost \*:$PORT>/g" /etc/apache2/sites-available/000-default.conf

# Ensure writable directories exist
mkdir -p /var/www/html/uploads /var/www/html/database /var/www/html/storage/logs
chown -R www-data:www-data /var/www/html/uploads /var/www/html/database /var/www/html/storage 2>/dev/null || true
chmod -R 775 /var/www/html/uploads /var/www/html/database /var/www/html/storage 2>/dev/null || true

# Execute Apache in foreground
exec apache2-foreground
