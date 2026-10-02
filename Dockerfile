FROM php:8.2-apache

WORKDIR /var/www/html

# Install PHP extensions and Node.js for Laravel asset compilation
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libpng-dev libonig-dev libxml2-dev zip curl ca-certificates gnupg \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Laravel expects the public folder to be the site root
RUN sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/apache2.conf

# Copy dependency manifests and install PHP + Node packages
COPY laravel-app/composer.* ./
RUN composer install --no-interaction --no-ansi --no-progress --prefer-dist --optimize-autoloader --no-dev --no-scripts

COPY laravel-app/ ./
RUN composer dump-autoload --no-dev --optimize

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 storage bootstrap/cache \
    && cp -n .env.example .env || true \
    && php artisan key:generate --force \
    && php artisan config:cache \
    && php artisan route:cache \
    && npm install --no-fund --no-audit \
    && npm run build

EXPOSE 80

CMD ["apache2-foreground"]
