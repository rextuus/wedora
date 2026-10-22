# syntax=docker/dockerfile:1
FROM php:8.4.27-fpm-alpine AS base

RUN apk add --no-cache icu-libs icu-data-full libzip fcgi ffmpeg \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev linux-headers \
 && docker-php-ext-install -j"$(nproc)" intl pdo_mysql zip opcache \
 && pecl install apcu \
 && docker-php-ext-enable apcu \
 && apk del .build-deps

COPY --from=composer:2.9 /usr/bin/composer /usr/bin/composer
COPY docker/php/conf.d/app.ini $PHP_INI_DIR/conf.d/zz-app.ini
COPY docker/php/php-fpm.d/zz-app.conf /usr/local/etc/php-fpm.d/zz-app.conf

# Privater Medienspeicher (Docker-Volume); gehört www-data, damit das Volume diese Rechte übernimmt.
RUN mkdir -p /srv/media && chown www-data:www-data /srv/media

WORKDIR /srv/app

FROM base AS dev
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
ARG UID=1000
ARG GID=1000
RUN apk add --no-cache shadow && usermod -u ${UID} www-data && groupmod -g ${GID} www-data \
 && chown www-data:www-data /srv/media
USER www-data

FROM base AS prod
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
ENV APP_ENV=prod
COPY --chown=www-data:www-data . .
RUN composer install --no-dev --classmap-authoritative --no-interaction --no-progress \
 && composer dump-env prod \
 && php bin/console asset-map:compile \
 && rm -rf var/cache/* && chown -R www-data:www-data var
USER www-data

# Caddy mit den öffentlichen Dateien (kompilierte Assets) aus dem Produktions-Image.
FROM caddy:2.11.7-alpine AS web
COPY docker/caddy/Caddyfile /etc/caddy/Caddyfile
COPY --from=prod /srv/app/public /srv/app/public
