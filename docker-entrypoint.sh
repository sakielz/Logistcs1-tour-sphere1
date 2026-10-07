#!/bin/sh
set -e

# Create .env from .env.example if not present (env vars injected by HostForge at runtime)
if [ ! -f /var/www/html/.env ]; then
    cp /var/www/html/.env.example /var/www/html/.env
fi

# Run Laravel bootstrap commands
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
