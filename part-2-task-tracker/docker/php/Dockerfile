# php-fpm image for the task-tracker API.
#
# Everything PHP-related (composer, console commands, tests) runs inside this
# container -- nothing is installed on the host.
FROM php:8.3-fpm

# git + unzip are needed by composer to fetch packages.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

# Extensions: pdo_mysql (Doctrine <-> MySQL), intl (Symfony), opcache (speed),
# redis (cache + rate limiter in later stages -- baked in now so the image
# never needs rebuilding for stage 2).
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql intl opcache redis

# Composer binary copied from the official image (never installed on the host).
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY conf.d/app.ini /usr/local/etc/php/conf.d/app.ini

COPY entrypoint.sh /usr/local/bin/app-entrypoint
COPY healthcheck.sh /usr/local/bin/app-healthcheck
RUN chmod +x /usr/local/bin/app-entrypoint /usr/local/bin/app-healthcheck

WORKDIR /app

ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]
