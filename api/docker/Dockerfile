# syntax=docker/dockerfile:1

FROM php:8.3-fpm-alpine

# ---------------------------------------------------------------------------
# System + PHP extensions
#   pdo_mysql : database driver
#   bcmath    : available for any downstream code that wants arbitrary
#               precision; the Money value object itself uses integer maths
#   opcache   : bytecode cache, the single biggest PHP throughput win
#   pcntl     : lets `queue:work` handle SIGTERM and finish the job in hand
#               instead of being killed mid-write on a deploy
# ---------------------------------------------------------------------------
RUN apk add --no-cache \
        bash \
        git \
        icu-dev \
        libzip-dev \
        mysql-client \
        oniguruma-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        bcmath \
        intl \
        zip \
        opcache \
        pcntl \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-libyamarket.ini

WORKDIR /var/www/html

# Dependencies are installed in their own layer so a source-only change does
# not invalidate the (slow) composer install.
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY . .

RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 9000

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
