# syntax=docker/dockerfile:1.7

###############################################################################
# MarketplaceOS — PHP 8.4 image
#
# Multi-stage, four targets:
#   vendor  — composer dependencies only, cached independently of source
#   base    — PHP-FPM + extensions, shared by dev and prod
#   dev     — base + Xdebug + dev dependencies, source bind-mounted
#   prod    — base + vendor + source, compiled config, non-root, read-only-ish
#
# The vendor stage exists so that editing a PHP file does not invalidate the
# composer install layer — the difference between a 5-second and a 3-minute
# rebuild.
###############################################################################

ARG PHP_VERSION=8.4

###############################################################################
# Stage: base
###############################################################################
FROM php:${PHP_VERSION}-fpm-alpine AS base

LABEL org.opencontainers.image.title="MarketplaceOS" \
      org.opencontainers.image.vendor="Turuncu Kasa" \
      org.opencontainers.image.licenses="proprietary"

RUN apk add --no-cache \
        bash \
        curl \
        git \
        icu-data-full \
        icu-dev \
        libzip-dev \
        linux-headers \
        oniguruma-dev \
        postgresql-dev \
        # Image processing for spatie/laravel-medialibrary conversions
        libjpeg-turbo-dev \
        libpng-dev \
        libwebp-dev \
        freetype-dev \
        tzdata \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        pgsql \
        sockets \
        zip \
    # phpredis: the C extension, not predis. Materially faster and what
    # config/database.php expects (REDIS_CLIENT=phpredis).
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear

# Europe/Istanbul, to match config/app.php. Container clocks in UTC with an
# app in +03 makes every log timestamp a mental subtraction.
ENV TZ=Europe/Istanbul
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

###############################################################################
# Stage: vendor — dependency resolution, cached on composer files alone
###############################################################################
FROM base AS vendor

COPY composer.json composer.lock* ./

RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

###############################################################################
# Stage: dev
###############################################################################
FROM base AS dev

RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apk del .build-deps

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-marketplaceos.ini
COPY docker/php/xdebug.ini /usr/local/etc/php/conf.d/99-xdebug.ini
COPY docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/zz-marketplaceos.conf

# Source is bind-mounted in development; nothing is COPYed so an edit is
# visible without a rebuild.
ENV APP_ENV=local

EXPOSE 9000
CMD ["php-fpm"]

###############################################################################
# Stage: prod
###############################################################################
FROM base AS prod

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-marketplaceos.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/99-opcache.ini
COPY docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/zz-marketplaceos.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

COPY --from=vendor /var/www/html/vendor ./vendor
COPY . .

RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && php artisan package:discover --ansi \
    # Config, routes and events are cached at build time so no container ever
    # pays the cost at first request — and so a broken config fails the BUILD
    # rather than the first user.
    && php artisan config:cache \
    && php artisan route:cache \
    && php artisan event:cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Never run the application as root.
USER www-data

ENV APP_ENV=production

EXPOSE 9000

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r "exit(@fsockopen('127.0.0.1', 9000) ? 0 : 1);"

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
