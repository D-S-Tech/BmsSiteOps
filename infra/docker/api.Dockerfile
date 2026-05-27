# =============================================================================
# BmsSiteOps API — Laravel 11 / PHP 8.3 / PHP-FPM Alpine
# =============================================================================
# Image is consumed by infra/compose/docker-compose.{dev,prod}.yml as service
# `api`. Caddy front-ends it via FastCGI on port 9000.
#
# Build context is the monorepo root; we operate inside apps/api/.
#
# Two-stage build:
#   1) base       — system packages + extensions + composer (reused by both)
#   2) production — final image with vendor/ baked in, opcache enabled
#
# Development uses the `base` stage with bind-mounted source. Production uses
# the `production` stage with COPY semantics so the image is self-contained.
# =============================================================================

ARG PHP_VERSION=8.3

# -----------------------------------------------------------------------------
# Stage 1 — base
# -----------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-alpine AS base

# System packages required by Laravel + PostgreSQL + Redis + image processing
RUN apk add --no-cache \
        bash \
        curl \
        git \
        icu-dev \
        icu-libs \
        libpng \
        libpng-dev \
        libjpeg-turbo \
        libjpeg-turbo-dev \
        libwebp \
        libwebp-dev \
        libzip \
        libzip-dev \
        oniguruma-dev \
        postgresql-client \
        postgresql-dev \
        zip \
        unzip \
    && rm -rf /var/cache/apk/*

# PHP extensions required by Laravel 11 + Filament + Horizon + pgsql + redis
RUN docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        bcmath \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_pgsql \
        zip

# PECL extensions: redis + igbinary (faster session serialization)
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install igbinary redis \
    && docker-php-ext-enable igbinary redis \
    && apk del .build-deps

# Composer 2 (multi-stage copy keeps our image lean)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Runtime user — Laravel 11 conventions
RUN addgroup -g 1000 -S app && adduser -u 1000 -S app -G app

# OPcache + PHP runtime config
COPY infra/docker/php.ini.production /usr/local/etc/php/conf.d/zz-bmssiteops.ini

WORKDIR /var/www/html

# PHP-FPM listens on 9000 by default; Caddy hits it via php_fastcgi
EXPOSE 9000

# Default command — PHP-FPM in foreground
CMD ["php-fpm", "-F"]

# -----------------------------------------------------------------------------
# Stage 2 — production
# -----------------------------------------------------------------------------
FROM base AS production

# Copy application code with correct ownership
COPY --chown=app:app apps/api/ /var/www/html/

# Install Composer dependencies (prod-only, optimized autoloader)
USER app
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --optimize-autoloader \
        --prefer-dist \
    && composer clear-cache

# Pre-build Laravel caches (warm OPcache friendliness)
# NOTE: requires .env to be present at runtime; we DO NOT bake .env into the
# image. The container reads .env from a bind-mount or env_file at runtime.
RUN php artisan storage:link || true

USER root

# Healthcheck — PHP-FPM ping endpoint via cli (config below in php.ini)
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r "exit(file_get_contents('http://127.0.0.1:9000/ping') === 'pong' ? 0 : 1);" || exit 1
