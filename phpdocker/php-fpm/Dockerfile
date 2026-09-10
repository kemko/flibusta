FROM composer:2 AS composer

WORKDIR /app
COPY application/composer.json application/composer.lock ./
RUN composer install --no-interaction --prefer-dist --optimize-autoloader

FROM php:8.1-fpm-alpine

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

WORKDIR "/application"

COPY --from=composer /app/vendor /opt/flibusta-vendor

RUN apk --update add postgresql-client;\
  apk add python3;\
  apk add coreutils;
RUN chmod +x /usr/local/bin/install-php-extensions; install-php-extensions curl gd bz2 mbstring pdo_pgsql zip
