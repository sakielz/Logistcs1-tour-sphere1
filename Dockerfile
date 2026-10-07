FROM php:8.2-apache

WORKDIR /var/www/html

# Install dependencies, Node.js 20, and PHP extensions (including pdo_pgsql for Supabase)
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libpng-dev libonig-dev libxml2-dev libpq-dev zip curl ca-certificates gnupg \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && docker-php-ext-install pdo_mysql pdo_pgsql pgsql mbstring exif pcntl bcmath gd \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Write a clean vhost config:
#   - DocumentRoot → /var/www/html/public
#   - AllowOverride All  (required for Laravel's .htaccess / mod_rewrite)
#   - Port placeholder __PORT__ replaced at runtime by the entrypoint
RUN printf '<VirtualHost *:__PORT__>\n\
    DocumentRoot /var/www/html/public\n\
    <Directory /var/www/html/public>\n\
        Options -Indexes +FollowSymLinks\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
    ErrorLog ${APACHE_LOG_DIR}/error.log\n\
    CustomLog ${APACHE_LOG_DIR}/access.log combined\n\
</VirtualHost>\n' > /etc/apache2/sites-available/000-default.conf

# Install Composer dependencies (layer cache: only re-runs if composer.json/lock changes)
COPY laravel-app/composer.* ./
RUN composer install --no-interaction --no-ansi --no-progress --prefer-dist --optimize-autoloader --no-dev --no-scripts

# Copy application files and build assets
COPY laravel-app/ ./
RUN composer dump-autoload --no-dev --optimize \
    && npm install --no-fund --no-audit \
    && npm run build \
    && rm -rf node_modules \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 storage bootstrap/cache

# Copy entrypoint (lives at repo root, not laravel-app/)
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
