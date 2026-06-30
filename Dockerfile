FROM php:8.2-fpm-alpine

# Build-time args / defaults
ARG APP_ENV=production
ENV APP_ENV=${APP_ENV}
ENV JWT_SECRET=change_me
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

# Install PHP dependencies early to leverage Docker cache.
# Copy the whole `api/` folder (safer if composer.lock is missing) and
# run composer install only when `composer.json` exists.
COPY api/ ./api/
WORKDIR /var/www/html/api
RUN if [ -f composer.json ]; then composer install --no-dev --no-interaction --optimize-autoloader --prefer-dist --no-scripts; fi

# Return to project root and copy the rest of the application code
WORKDIR /var/www/html
COPY . .

# Ensure proper permissions for PHP-FPM user
RUN chown -R www-data:www-data /var/www/html \
 && find /var/www/html -type d -exec chmod 755 {} \; \
 && find /var/www/html -type f -exec chmod 644 {} \;

EXPOSE 9000
CMD ["php-fpm"]
