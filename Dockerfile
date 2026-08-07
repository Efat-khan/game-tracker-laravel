# syntax=docker/dockerfile:1

###############################################################################
# CafeTrack — PHP-FPM image
###############################################################################

FROM php:8.3-fpm-alpine AS base

RUN apk add --no-cache \
        icu-dev oniguruma-dev libzip-dev libpng-dev freetype-dev libjpeg-turbo-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql bcmath intl zip gd opcache \
    && apk del icu-dev oniguruma-dev libzip-dev

# bcmath is installed above purely for speed: brick/math picks it up
# automatically and falls back to a pure-PHP calculator without it. Money is
# never a float either way.

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Sensible production PHP defaults.
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.memory_consumption=192'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'expose_php=Off'; \
        echo 'date.timezone=UTC'; \
        echo 'upload_max_filesize=8M'; \
        echo 'post_max_size=8M'; \
    } > /usr/local/etc/php/conf.d/cafetrack.ini

###############################################################################
# Dependencies — cached separately from the source
###############################################################################

FROM base AS vendor

COPY composer.json composer.lock ./

RUN composer install \
        --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

###############################################################################
# Frontend — the React SPA Laravel serves
###############################################################################

FROM node:22-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.js ./
COPY resources ./resources

# No network needed: there is no webfont to fetch.
RUN npm run build

###############################################################################
# Application
###############################################################################

FROM base AS app

COPY --from=vendor /var/www/html/vendor ./vendor
COPY . .
COPY --from=frontend /app/public/build ./public/build

RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x docker/entrypoint.sh

ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["php-fpm"]
