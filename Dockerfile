FROM php:8.2-apache

WORKDIR /var/www/html

# Install system packages and development libraries
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    zip \
    curl \
    ca-certificates \
    libpq-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_pgsql \
        pgsql \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Configure Apache DocumentRoot and AllowOverride for .htaccess
RUN echo '<Directory /var/www/html/>' >> /etc/apache2/apache2.conf \
    && echo '    Options -Indexes +FollowSymLinks' >> /etc/apache2/apache2.conf \
    && echo '    AllowOverride All' >> /etc/apache2/apache2.conf \
    && echo '    Require all granted' >> /etc/apache2/apache2.conf \
    && echo '</Directory>' >> /etc/apache2/apache2.conf

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy application files
COPY . /var/www/html/

# Install composer packages if composer.json exists (safe non-blocking)
RUN if [ -f "composer.json" ]; then \
        composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts 2>/dev/null || true; \
    fi

# Create required directories and set permissions
RUN mkdir -p /var/www/html/uploads /var/www/html/database /var/www/html/storage/logs \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/uploads /var/www/html/database

# Copy entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/docker-entrypoint.sh \
    && chmod +x /usr/local/bin/docker-entrypoint.sh

# Render and cloud platforms inject $PORT
EXPOSE 80

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
