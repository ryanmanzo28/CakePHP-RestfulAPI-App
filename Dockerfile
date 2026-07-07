FROM php:8.2-fpm-alpine

# Build-time args / defaults
ARG APP_ENV=production
ENV APP_ENV=${APP_ENV}
ENV DB_HOST=db
ENV DB_NAME=cakephp
ENV DB_USER=cakeuser
ENV DB_PASS=secret

# System deps
RUN apk add --no-cache --update \
    icu-dev libzip-dev oniguruma-dev zlib-dev zip unzip git bash curl \
 && docker-php-ext-configure intl \
 && docker-php-ext-install pdo pdo_mysql mbstring intl opcache zip \
 && rm -rf /var/cache/apk/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy composer files first for dependency caching (root project composer.json)
COPY api/composer.json api/composer.lock* /var/www/html/api/

# Install PHP dependencies for the API directory
RUN if [ -f /var/www/html/api/composer.json ]; then \
      cd /var/www/html/api && \
      composer config --no-interaction --global http-basic.packagist.org no-http false && \
      composer install --no-dev --no-interaction --optimize-autoloader --prefer-dist --no-scripts 2>&1; \
    fi

# Copy the rest of the application code
COPY . .

# Container startup script runs migrations and then starts php-fpm
RUN chmod +x /var/www/html/docker/entrypoint.sh

# Ensure proper permissions for PHP-FPM user
RUN chown -R www-data:www-data /var/www/html \
 && find /var/www/html -type d -exec chmod 755 {} \; \
 && find /var/www/html -type f -exec chmod 644 {} \;

EXPOSE 9000
ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
CMD ["php-fpm"]
