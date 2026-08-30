# syntax=docker/dockerfile:1

# Keep in sync with composer.json's "php" constraint and .github/workflows/ci.yml's php-version matrix.
ARG PHP_VERSION=8.4

########################################
# base: PHP + extensions shared by every later stage
# -----------( Prepare PHP )------------
########################################
FROM php:${PHP_VERSION}-fpm-alpine AS base

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/bin/

RUN install-php-extensions \
    gd \
    opcache \
    pdo_pgsql \
    pgsql \
    bcmath \
    pcntl \
    exif \
    zip \
    intl

WORKDIR /var/www/html

########################################
# deps: composer install, cached independently of app source
# --------( install packages )---------
########################################
FROM base AS deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --no-interaction \
    --prefer-dist

##############################################################
# build: full app source + optimized autoloader
# --( prepare composer autoloading and package information )--
##############################################################
FROM deps AS build

COPY . .

RUN composer dump-autoload --optimize --no-dev --classmap-authoritative \
 && php artisan package:discover --ansi

########################################
# runtime: final production image, no nginx (fronted by a separate service)
########################################
FROM base AS runtime

ENV APP_ENV=production \
    APP_DEBUG=false

COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/php-fpm.d/zzz-app.conf /usr/local/etc/php-fpm.d/zzz-app.conf
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/entrypoint.sh

# --chown covers storage/ and bootstrap/cache/ too, which the entrypoint and
# the running app need to write to.
COPY --from=build --chown=www-data:www-data /var/www/html /var/www/html

USER www-data

EXPOSE 9000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
