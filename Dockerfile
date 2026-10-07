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

# Set DocumentRoot to Laravel's public directory
RUN sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/apache2.conf

# Support HostForge dynamic $PORT
RUN sed -ri -e 's/Listen 80/Listen ${PORT:-80}/g' /etc/apache2/ports.conf \
    && sed -ri -e 's/:80/:${PORT:-80}/g' /etc/apache2/sites-available/000-default.conf

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

# Copy entrypoint (must be COPY'd separately — it lives at repo root, not laravel-app/)
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
